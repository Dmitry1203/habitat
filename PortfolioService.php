<?php

namespace App\Services;

use App\Models\PortfolioProject;
use Imagick;
use ImagickException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PortfolioService
{
    private const SLUG_EXISTS_MESSAGE = 'Проект с такой ссылкой уже существует. Укажите другое название.';

    /**
     * Однократно закрепляет существующий шаблон сетки за проектом.
     *
     * В транзакции повторно читает проект с блокировкой строки. Проверка NULL
     * выполняется по свежей записи, чтобы два параллельных запроса не могли
     * сохранить разные шаблоны. Переданную модель в памяти не обновляет.
     *
     * @param PortfolioProject $project Проект, для которого выбирается шаблон.
     * @param int $templateId Ключ шаблона из config('portfolio.templates').
     * @return void
     * @throws ValidationException Если шаблон неизвестен или уже был выбран.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если проект больше не существует.
     * @throws RuntimeException Если модель отклонила сохранение.
     * @throws Throwable При других ошибках чтения или записи базы.
     */
    public function selectTemplate(PortfolioProject $project, int $templateId): void
    {
        if (! array_key_exists($templateId, config('portfolio.templates'))) {
            throw ValidationException::withMessages(['grid_template' => 'Выберите существующий шаблон.']);
        }

        DB::transaction(function () use ($project, $templateId) {
            $current = PortfolioProject::query()->lockForUpdate()->findOrFail($project->getKey());

            if ($current->grid_template !== null) {
                throw ValidationException::withMessages(['grid_template' => 'Шаблон уже выбран. Заменить его нельзя.']);
            }

            $current->grid_template = $templateId;

            if (! $current->save()) {
                throw new RuntimeException('Failed to save the portfolio grid template.');
            }
        });
    }

    /**
     * Создаёт проект с уникальным slug и сохранённой обложкой.
     *
     * Файл записывается до создания строки. Если транзакция не завершилась,
     * предпринимается удаление нового файла; сбой очистки журналируется.
     * Нарушение уникального ограничения преобразуется в ошибку поля name.
     * Размеры и формат обложки должен заранее проверить Form Request.
     *
     * @param string $name Название проекта, из которого формируется slug.
     * @param string $description Описание обычным текстом с исходными переносами.
     * @param UploadedFile $cover Проверенный файл обложки; сохраняется без обработки.
     * @param string $direction Ключ направления из маршрута, не из данных формы.
     * @return PortfolioProject Созданная запись проекта.
     * @throws ValidationException Если slug недопустим, занят или направление неизвестно.
     * @throws RuntimeException Если файл обложки записать не удалось.
     * @throws Throwable При других ошибках файловой системы или базы.
     */
    public function create(string $name, string $description, UploadedFile $cover, string $direction): PortfolioProject
    {
        if (! array_key_exists($direction, config('portfolio.directions'))) {
            throw ValidationException::withMessages(['direction' => 'Выберите существующее направление проектов.']);
        }

        $slug = $this->makeSlug($name);
        $coverPath = $this->storeCover($cover);

        try {
            return DB::transaction(fn () => PortfolioProject::query()->create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'cover_path' => $coverPath,
                'direction' => $direction,
            ]));
        } catch (Throwable $exception) {
            $this->removeCover($coverPath);

            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => self::SLUG_EXISTS_MESSAGE]);
            }

            throw $exception;
        }
    }

    /**
     * Обновляет проект и при необходимости заменяет его обложку.
     *
     * Новый файл записывается до транзакции. Заблокированная свежая запись
     * определяет прежний путь и необходимость пересоздать slug. При ошибке
     * записи новый файл очищается; прежний удаляется только после коммита.
     * При cover = NULL прежняя обложка не изменяется. Переданная модель
     * в памяти не обновляется: изменения сохраняет повторно загруженная запись.
     *
     * @param PortfolioProject $project Идентификатор обновляемого проекта берётся из модели.
     * @param string $name Новое название; slug меняется только при его изменении.
     * @param string $description Новое текстовое описание.
     * @param UploadedFile|null $cover Проверенная новая обложка либо NULL для сохранения прежней.
     * @return bool true, если очистка не нужна или успешна; false, если запись обновлена, но старый файл не удалён.
     * @throws ValidationException Если новое название не даёт уникального допустимого slug.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если проект уже удалён.
     * @throws RuntimeException Если сохранение файла или модели не удалось.
     * @throws Throwable При других ошибках файловой системы или транзакции.
     */
    public function update(PortfolioProject $project, string $name, string $description, ?UploadedFile $cover = null): bool
    {
        $newCoverPath = $cover !== null ? $this->storeCover($cover) : null;

        try {
            $oldCoverPath = DB::transaction(function () use ($project, $name, $description, $newCoverPath) {
                $current = PortfolioProject::query()->lockForUpdate()->findOrFail($project->getKey());
                $oldCoverPath = $current->cover_path;
                $attributes = ['name' => $name, 'description' => $description];

                if ($name !== $current->name) {
                    $attributes['slug'] = $this->makeSlug($name, $current->getKey());
                }

                if ($newCoverPath !== null) {
                    $attributes['cover_path'] = $newCoverPath;
                }

                if (! $current->fill($attributes)->save()) {
                    throw new RuntimeException('Failed to update the portfolio project.');
                }

                return $oldCoverPath;
            });
        } catch (Throwable $exception) {
            if ($newCoverPath !== null) {
                $this->removeCover($newCoverPath);
            }

            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => self::SLUG_EXISTS_MESSAGE]);
            }

            throw $exception;
        }

        return $newCoverPath === null || $this->removeCover($oldCoverPath);
    }

    /**
     * Сохраняет оригинал фото и привязывает его к ячейке проекта.
     *
     * До записи файла и повторно под блокировкой проекта проверяет ячейку.
     * В транзакции создаёт запись либо меняет путь существующей пары
     * project_id + cell. После коммита очищает прежний файл; при сбое записи
     * удаляет новый. Общая блокировка проекта согласует замену с его удалением.
     * Изменение размеров и сжатие изображения не выполняются.
     *
     * @param PortfolioProject $project Проект с выбранным шаблоном сетки.
     * @param int $cell Номер ячейки, начиная с 1.
     * @param UploadedFile $photo Проверенный файл JPEG, PNG или WebP.
     * @return bool true, если прежнего файла не было или он удалён; false при сбое его очистки после сохранения фото.
     * @throws ValidationException Если сетка не выбрана или номер ячейки недопустим.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если проект удалён во время загрузки.
     * @throws RuntimeException Если файл или запись фотографии сохранить не удалось.
     * @throws Throwable При других ошибках файловой системы или транзакции.
     */
    public function storePhoto(PortfolioProject $project, int $cell, UploadedFile $photo): bool
    {
        $this->validatePhotoCell($project, $cell);
        $fileName = $photo->hashName();
        $newPath = 'portfolio/photos/'.$fileName;

        try {
            if (Storage::disk('public')->putFileAs('portfolio/photos', $photo, $fileName) === false) {
                throw new RuntimeException('Failed to store the portfolio photo.');
            }

            $oldPath = DB::transaction(function () use ($project, $cell, $newPath) {
                $current = PortfolioProject::query()->lockForUpdate()->findOrFail($project->getKey());
                $this->validatePhotoCell($current, $cell);
                $record = $current->photos()->where('cell', $cell)->first();
                $oldPath = $record?->path;

                if ($record === null) {
                    $record = $current->photos()->make(['cell' => $cell]);
                    $record->sort_order = ((int) $current->photos()->max('sort_order')) + 1;
                }

                $record->path = $newPath;

                if (! $record->save()) {
                    throw new RuntimeException('Failed to save the portfolio photo record.');
                }

                return $oldPath;
            });
        } catch (Throwable $exception) {
            $this->removePhoto($newPath);

            throw $exception;
        }

        return $oldPath === null || $this->removePhoto($oldPath);
    }

    /**
     * Добавляет партию фотографий с оригиналами и копиями для свободной сетки.
     *
     * Получает уже проверенные JPEG. Последовательно создаёт пары файлов,
     * затем под блокировкой проекта записывает всю партию одной транзакцией.
     * Каждая запись имеет cell = NULL. Любой сбой отменяет записи и запускает
     * очистку всех файлов партии, включая частично сохранённую текущую пару.
     * Существующие фотографии проекта не изменяются.
     *
     * @param PortfolioProject $project Проект для добавления фотографий.
     * @param list<UploadedFile> $photos Проверенная непустая партия JPEG.
     * @return int Количество добавленных фотографий после коммита.
     * @throws ValidationException Если один из JPEG не удалось обработать.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если проект удалён во время обработки.
     * @throws RuntimeException Если Imagick недоступен или запись файлов/моделей не удалась.
     * @throws Throwable При других ошибках; очистка новых файлов предпринимается перед повторным выбросом.
     */
    public function storePhotos(PortfolioProject $project, array $photos): int
    {
        if (! class_exists(Imagick::class)) {
            throw new RuntimeException('Imagick extension is required for portfolio photo uploads.');
        }

        $records = [];

        try {
            foreach ($photos as $index => $photo) {
                $fileName = Str::uuid().'.jpg';
                $record = [
                    'cell' => null,
                    'path' => 'portfolio/photos/'.$fileName,
                    'original_path' => 'portfolio/originals/'.$fileName,
                ];
                $records[] = $record;

                try {
                    $this->storePhotoVersions($photo, $record['path'], $record['original_path']);
                } catch (ImagickException $exception) {
                    report($exception);

                    throw ValidationException::withMessages([
                        'photos.'.$index => 'Не удалось обработать фото «'.$photo->getClientOriginalName().'». Партия не добавлена. Проверьте JPEG и повторите попытку.',
                    ]);
                }
            }

            DB::transaction(function () use ($project, $records): void {
                $current = PortfolioProject::query()->lockForUpdate()->findOrFail($project->getKey());
                $position = (int) $current->photos()->max('sort_order');

                foreach ($records as $attributes) {
                    $record = $current->photos()->make($attributes);
                    $record->sort_order = ++$position;

                    if (! $record->save()) {
                        throw new RuntimeException('Failed to save the portfolio photo batch.');
                    }
                }
            });
        } catch (Throwable $exception) {
            foreach ($records as $record) {
                $this->removePhoto($record['path']);
                $this->removeOriginal($record['original_path']);
            }

            throw $exception;
        }

        return count($records);
    }

    /**
     * Сверяет состав фото проекта и сохраняет позиции одной транзакцией.
     *
     * Блокировка проекта согласована с добавлением фото и удалением проекта.
     * Поле cell, пути файлов и оригиналы при сортировке не меняются.
     *
     * @param PortfolioProject $project Проект, которому принадлежат все фото.
     * @param array<int, int|string> $ids Полный список уникальных ID по порядку.
     * @return void
     * @throws ValidationException Если список неполный, устарел или содержит чужие ID.
     * @throws RuntimeException Если запись позиции отклонена моделью.
     */
    public function sortPhotos(PortfolioProject $project, array $ids): void
    {
        DB::transaction(function () use ($project, $ids): void {
            $current = PortfolioProject::query()->lockForUpdate()->findOrFail($project->getKey());
            $photos = $current->photos()->orderBy('id')->lockForUpdate()->get();
            $ordered = array_map('strval', $ids);
            $expected = array_map('strval', $photos->modelKeys());
            $actual = $ordered;
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);

            if ($actual !== $expected) {
                throw ValidationException::withMessages([
                    'sorting' => 'Состав фотографий изменился. Обновите страницу и повторите сортировку.',
                ]);
            }

            $positions = array_flip($ordered);

            foreach ($photos as $photo) {
                $photo->sort_order = $positions[(string) $photo->getKey()] + 1;

                if ($photo->isDirty('sort_order') && ! $photo->save()) {
                    throw new RuntimeException('Failed to save the portfolio photo sorting order.');
                }
            }
        });
    }

    /**
     * Записывает неизменённый оригинал и отдельную JPEG-копию для сетки.
     *
     * Оригинал копирует на закрытый диск local. Imagick работает с временным
     * файлом загрузки, учитывает EXIF-ориентацию и уменьшает ширину до 800 px
     * без обрезки и увеличения маленьких фото. Цветовое пространство копии
     * приводится к sRGB, метаданные удаляются, качество JPEG устанавливается 85.
     * На диске public хранится только эта копия. Ресурсы изображения очищаются
     * в finally; очисткой файлов при ошибке управляет вызывающий storePhotos().
     *
     * @param UploadedFile $photo Проверенный оригинальный JPEG.
     * @param string $previewPath Относительный путь уменьшенного фото на public.
     * @param string $originalPath Относительный путь оригинала на local.
     * @return void
     * @throws ImagickException Если JPEG не декодируется или обработка не удалась.
     * @throws RuntimeException Если чтение или запись вернули отказ.
     */
    private function storePhotoVersions(UploadedFile $photo, string $previewPath, string $originalPath): void
    {
        if (Storage::disk('local')->putFileAs(dirname($originalPath), $photo, basename($originalPath)) === false) {
            throw new RuntimeException('Failed to store the original portfolio photo.');
        }

        $sourcePath = $photo->getRealPath();

        if ($sourcePath === false) {
            throw new RuntimeException('Uploaded portfolio photo is unavailable.');
        }

        $image = new Imagick();

        try {
            if (! $image->readImage($sourcePath)) {
                throw new RuntimeException('Failed to read the uploaded JPEG.');
            }

            $image->setIteratorIndex(0);
            $image->autoOrient();
            $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

            if ($image->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            if ($image->getImageWidth() > 800 && ! $image->thumbnailImage(800, 0)) {
                throw new RuntimeException('Failed to resize the portfolio photo.');
            }

            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);

            if (Storage::disk('public')->put($previewPath, $image->getImageBlob()) === false) {
                throw new RuntimeException('Failed to store the portfolio photo preview.');
            }
        } finally {
            $image->clear();
        }
    }

    /**
     * Удаляет оригинал фотографии с закрытого диска local.
     *
     * Используется при отмене партии и удалении проекта. Ошибка журналируется
     * и возвращается как false, чтобы продолжить очистку остальных файлов.
     *
     * @param string $path Относительный путь оригинала на диске local.
     * @return bool true при успешной очистке, иначе false.
     */
    private function removeOriginal(string $path): bool
    {
        try {
            if (Storage::disk('local')->delete($path)) {
                return true;
            }

            report(new RuntimeException('Failed to remove the original portfolio photo: '.$path));
        } catch (Throwable $exception) {
            report($exception);
        }

        return false;
    }

    /**
     * Удаляет проект и затем очищает обложку, копии фото и закрытые оригиналы.
     *
     * Под блокировкой собирает пути, пока записи фотографий ещё существуют.
     * База удаляет их через ON DELETE CASCADE вместе с проектом. Файлы
     * удаляются после коммита; неудача одного удаления не прерывает остальные.
     * Если удаление записи не состоялось, очистка файлов не начинается.
     *
     * @param PortfolioProject $project Проект для удаления, найденный контроллером.
     * @return bool true при полной очистке файлов; false, если проект удалён, но часть файлов осталась.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если проект уже удалён.
     * @throws RuntimeException Если модель отклонила удаление записи.
     * @throws Throwable При других ошибках транзакции; ошибки очистки файлов журналируются внутри помощников.
     */
    public function delete(PortfolioProject $project): bool
    {
        [$coverPath, $photos] = DB::transaction(function () use ($project) {
            $current = PortfolioProject::query()->lockForUpdate()->findOrFail($project->getKey());
            $coverPath = $current->cover_path;
            $photos = $current->photos()->get(['path', 'original_path'])->map(fn ($photo) => [
                'path' => $photo->path,
                'original_path' => $photo->original_path,
            ])->all();

            if (! $current->delete()) {
                throw new RuntimeException('Failed to delete the portfolio project.');
            }

            return [$coverPath, $photos];
        });

        $filesRemoved = $this->removeCover($coverPath);

        foreach ($photos as $photo) {
            if (! $this->removePhoto($photo['path'])) {
                $filesRemoved = false;
            }

            if ($photo['original_path'] !== null && ! $this->removeOriginal($photo['original_path'])) {
                $filesRemoved = false;
            }
        }

        return $filesRemoved;
    }

    /**
     * Удаляет одну фотографию проекта и после коммита очищает её файлы.
     *
     * Блокирует сначала проект, затем фото, как загрузка и сортировка галереи.
     * Ищет фото через связь проекта: ID другого проекта не позволяет удалить
     * запись или файл. Пути берутся из свежей записи до её удаления. При ошибке
     * транзакции очистка не начинается. Для старых фото original_path = NULL,
     * поэтому удаляется только path с диска public. Оба удаления файлов
     * выполняются независимо: сбой одного не мешает попытке очистить второй.
     *
     * @param PortfolioProject $project Проект, найденный с проверкой направления.
     * @param string $photoId ID фотографии внутри этого проекта.
     * @return bool true при полной очистке; false, если запись удалена, но остались файлы.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если проект или его фото не найдены.
     * @throws RuntimeException Если модель отклонила удаление фотографии.
     * @throws Throwable При ошибке транзакции; ошибки файлов журналируются помощниками.
     */
    public function deletePhoto(PortfolioProject $project, string $photoId): bool
    {
        [$path, $originalPath] = DB::transaction(function () use ($project, $photoId): array {
            $current = PortfolioProject::query()->lockForUpdate()->findOrFail($project->getKey());
            $photo = $current->photos()->lockForUpdate()->findOrFail($photoId);
            $paths = [$photo->path, $photo->original_path];

            if (! $photo->delete()) {
                throw new RuntimeException('Failed to delete the portfolio photo.');
            }

            return $paths;
        });

        $filesRemoved = $this->removePhoto($path);

        if ($originalPath !== null && ! $this->removeOriginal($originalPath)) {
            $filesRemoved = false;
        }

        return $filesRemoved;
    }

    /**
     * Проверяет наличие выбранного шаблона и допустимость номера ячейки.
     *
     * Количество ячеек берётся из конфигурации, а не из пользовательского
     * запроса. Этот метод проверяет только сетку и не проверяет сам файл.
     *
     * @param PortfolioProject $project Проект с актуальным значением grid_template.
     * @param int $cell Проверяемый номер ячейки, начиная с 1.
     * @return void
     * @throws ValidationException Ошибка поля photo при отсутствии шаблона или ячейки.
     */
    private function validatePhotoCell(PortfolioProject $project, int $cell): void
    {
        $templates = config('portfolio.templates');
        $template = $templates[$project->grid_template] ?? null;

        if ($template === null) {
            throw ValidationException::withMessages(['photo' => 'Сначала выберите шаблон сетки.']);
        }

        if ($cell < 1 || $cell > count($template['cells'])) {
            throw ValidationException::withMessages(['photo' => 'В выбранном шаблоне нет такой ячейки.']);
        }
    }

    /**
     * Удаляет файл галереи с диска public и сообщает результат очистки.
     *
     * Исключения файловой системы перехватываются и передаются в report().
     * Ошибка очистки не должна скрывать исходную ошибку сохранения либо
     * отменять сообщение о уже сохранённой записи.
     *
     * @param string $path Относительный путь файла на диске public.
     * @return bool Результат удаления; false при отказе диска или перехваченном исключении.
     */
    private function removePhoto(string $path): bool
    {
        try {
            if (Storage::disk('public')->delete($path)) {
                return true;
            }

            report(new RuntimeException('Failed to remove the portfolio photo: '.$path));
        } catch (Throwable $exception) {
            report($exception);
        }

        return false;
    }

    /**
     * Формирует slug из названия и проверяет его длину и уникальность.
     *
     * При обновлении исключает текущий проект из проверки. Это предварительная
     * проверка для понятной ошибки формы: окончательную уникальность при
     * параллельной записи обеспечивает уникальный индекс базы.
     *
     * @param string $name Название, передаваемое в Str::slug().
     * @param int|null $ignoreId ID текущего проекта либо NULL при создании.
     * @return string Непустой slug длиной не более 255 байт.
     * @throws ValidationException Ошибка поля name, если slug пуст, слишком длинный или занят.
     */
    private function makeSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);

        if ($slug === '' || strlen($slug) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Измените название: из него не удалось сформировать ссылку длиной до 255 символов.',
            ]);
        }

        $query = PortfolioProject::query()->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => self::SLUG_EXISTS_MESSAGE]);
        }

        return $slug;
    }

    /**
     * Сохраняет обложку без преобразования с генерируемым именем файла.
     *
     * Использует каталог portfolio/covers на диске public. При ошибке записи
     * предпринимает очистку файла по рассчитанному пути и повторно выбрасывает
     * исходное исключение. Валидация изображения выполняется до вызова.
     *
     * @param UploadedFile $cover Проверенный оригинал обложки.
     * @return string Относительный путь для поля cover_path.
     * @throws RuntimeException Если диск вернул false при сохранении.
     * @throws Throwable Если файловая система выбросила исключение.
     */
    private function storeCover(UploadedFile $cover): string
    {
        $fileName = $cover->hashName();
        $coverPath = 'portfolio/covers/'.$fileName;

        try {
            if (Storage::disk('public')->putFileAs('portfolio/covers', $cover, $fileName) === false) {
                throw new RuntimeException('Failed to store the portfolio cover.');
            }
        } catch (Throwable $exception) {
            $this->removeCover($coverPath);

            throw $exception;
        }

        return $coverPath;
    }

    /**
     * Удаляет обложку с диска public без распространения ошибок очистки.
     *
     * Отказ удаления и исключения передаются в report(). Вызывающая операция
     * использует результат для предупреждения о неочищенном файле.
     *
     * @param string $coverPath Относительный путь обложки на диске public.
     * @return bool true при успешном удалении, иначе false.
     */
    private function removeCover(string $coverPath): bool
    {
        try {
            if (Storage::disk('public')->delete($coverPath)) {
                return true;
            }

            report(new RuntimeException('Failed to remove the portfolio cover: '.$coverPath));
        } catch (Throwable $exception) {
            report($exception);
        }

        return false;
    }
}
