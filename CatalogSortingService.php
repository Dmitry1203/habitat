<?php

namespace App\Services;

use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CatalogSortingService
{
    /**
     * Сохраняет полный порядок разделов первого уровня одной транзакцией.
     *
     * Выбирает только parent_id = NULL. Блокировка и проверка состава списка
     * не позволяют затронуть подразделы или сохранить неполную последовательность.
     *
     * @param array<int, int|string> $ids Проверенные ID в желаемом порядке.
     * @return void
     * @throws ValidationException Если состав списка изменился или содержит чужие ID.
     * @throws RuntimeException Если модель отклонила сохранение.
     * @throws \Illuminate\Database\QueryException При ошибке базы; изменения откатываются.
     */
    public function sections(array $ids): void
    {
        DB::transaction(function () use ($ids): void {
            $records = ServiceSection::query()->whereNull('parent_id')->orderBy('id')->lockForUpdate()->get();
            $this->saveOrder($records, $ids);
        });
    }

    /**
     * Сохраняет порядок всех подразделов одного верхнего раздела.
     *
     * Блокирует сначала родителя, затем его подразделы, как операции удаления
     * и создания в ServiceSectionService. Соседние разделы не затрагиваются.
     *
     * @param ServiceSection $parent Верхний раздел из маршрута.
     * @param array<int, int|string> $ids Полная последовательность ID его подразделов.
     * @return void
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если верхний раздел не найден.
     * @throws ValidationException Если состав списка не совпадает с текущим содержимым.
     * @throws RuntimeException Если модель отклонила запись.
     */
    public function subsections(ServiceSection $parent, array $ids): void
    {
        DB::transaction(function () use ($parent, $ids): void {
            $root = ServiceSection::query()->whereNull('parent_id')->lockForUpdate()->findOrFail($parent->getKey());
            $records = $root->children()->reorder('id')->lockForUpdate()->get();
            $this->saveOrder($records, $ids);
        });
    }

    /**
     * Сохраняет порядок всех услуг одного подраздела второго уровня.
     *
     * Повторно проверяет иерархию под блокировками «раздел, подраздел, услуги».
     * Услуги других подразделов и услуги непосредственно верхнего уровня
     * не могут попасть в обновление даже при подмене JSON или адреса запроса.
     *
     * @param ServiceSection $parent Верхний раздел из маршрута.
     * @param string $subsection ID подраздела этого раздела.
     * @param array<int, int|string> $ids Полный порядок услуг подраздела.
     * @return void
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если иерархия не найдена.
     * @throws ValidationException Если состав услуг изменился или содержит чужие ID.
     * @throws RuntimeException Если модель отклонила запись.
     */
    public function services(ServiceSection $parent, string $subsection, array $ids): void
    {
        DB::transaction(function () use ($parent, $subsection, $ids): void {
            $root = ServiceSection::query()->whereNull('parent_id')->lockForUpdate()->findOrFail($parent->getKey());
            $section = $root->children()->lockForUpdate()->findOrFail($subsection);
            $records = $section->services()->reorder('id')->lockForUpdate()->get();
            $this->saveOrder($records, $ids);
        });
    }

    /**
     * Сверяет полный состав списка и назначает позиции от нуля по порядку ID.
     *
     * Вызывается только с уже заблокированной коллекцией внутри транзакции.
     * Сначала проверяет все ID, затем сохраняет изменения: чужой, пропущенный
     * или повторяющийся ID не приводит к частичному обновлению. Числовые строки
     * нормализуются без преобразования BIGINT в JavaScript Number на клиенте.
     *
     * @param Collection $records Все допустимые записи текущего списка.
     * @param array<int, int|string> $ids Проверенный желаемый порядок.
     * @return void
     * @throws ValidationException Если набор ID не совпал со свежими данными базы.
     * @throws RuntimeException Если сохранение хотя бы одной модели отклонено.
     */
    private function saveOrder(Collection $records, array $ids): void
    {
        $ordered = array_map('strval', $ids);
        $expected = array_map('strval', $records->modelKeys());
        $actual = $ordered;
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw ValidationException::withMessages([
                'sorting' => 'Состав списка изменился. Обновите страницу и повторите сортировку.',
            ]);
        }

        $positions = array_flip($ordered);

        foreach ($records as $record) {
            $record->sort_order = $positions[(string) $record->getKey()];

            if ($record->isDirty('sort_order') && ! $record->save()) {
                throw new RuntimeException('Failed to save the catalog sorting order.');
            }
        }
    }
}
