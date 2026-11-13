<?php

namespace App\Http\Controllers;

use App\Models\PortfolioProject;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PublicPortfolioController extends Controller
{
    /**
     * Показывает карточки подразделов выбранной публичной группы.
     *
     * Название и состав группы берутся из общей конфигурации. Флаг public
     * исключает «Нашу историю» из публичных адресов, сохраняя её в админке.
     * Изображения подразделов также заданы в конфигурации и могут заменяться
     * независимо от ключей direction, которые уже записаны у проектов.
     *
     * @param string $group Постоянный ключ группы из URL.
     * @return View Страница portfolio.group с карточками подразделов.
     */
    public function group(string $group): View
    {
        $groupData = $this->publicGroup($group);

        return view('portfolio.group', compact('group', 'groupData'));
    }

    /**
     * Показывает обложки и названия проектов выбранного направления.
     *
     * Проверка конфигурации подтверждает принадлежность подраздела группе.
     * Выборка по direction исключает проекты других направлений.
     * Все проекты выводятся сразу, новые первыми,
     * как в административном списке; фотографии здесь не загружаются.
     *
     * @param string $group Ключ публичной группы.
     * @param string $direction Ключ подраздела внутри группы.
     * @return View Список проектов с названием направления из конфигурации.
     */
    public function index(string $group, string $direction): View
    {
        $groupData = $this->publicGroup($group, $direction);
        $projects = PortfolioProject::query()->where('direction', $direction)
            ->orderByDesc('id')->get();
        $directionName = $groupData['directions'][$direction];

        return view('portfolio.index', compact('projects', 'group', 'groupData', 'direction', 'directionName'));
    }

    /**
     * Показывает описание проекта и фотографии свободной галереи.
     *
     * PortfolioProject::resolveRouteBinding() ищет slug внутри direction:
     * чужой или отсутствующий проект возвращает 404 до входа в метод.
     * Старые фотографии ячеек исключаются условием cell IS NULL. Порядок
     * sort_order, ID совпадает с административной сортировкой фотографий.
     * В браузер передаются публичные версии шириной до 800 пикселей;
     * закрытые оригиналы не публикуются и административные ссылки не нужны.
     * Размеры публичных файлов определяются по заголовкам изображений:
     * PhotoSwipe использует их для пропорций слайда ещё до загрузки фото.
     * Если файл отсутствует или повреждён, ссылка галереи для него не создаётся.
     * Принадлежность подраздела публичной группе проверяется до загрузки файлов.
     *
     * @param string $group Ключ публичной группы.
     * @param string $direction Ключ подраздела внутри группы.
     * @param PortfolioProject $portfolio Проект, найденный по slug и направлению.
     * @return View Страница проекта с описанием и фотографиями для Masonry.
     */
    public function show(string $group, string $direction, PortfolioProject $portfolio): View
    {
        $groupData = $this->publicGroup($group, $direction);
        $project = $portfolio;
        $photos = $project->photos()->whereNull('cell')
            ->orderBy('sort_order')->orderBy('id')->get();
        $photoSizes = [];
        $disk = Storage::disk('public');
        foreach ($photos as $photo) {
            $path = $disk->path($photo->path);
            $size = is_file($path) ? @getimagesize($path) : false;
            if ($size !== false && $size[0] > 0 && $size[1] > 0) {
                $photoSizes[$photo->getKey()] = [$size[0], $size[1]];
            }
        }
        $directionName = $groupData['directions'][$direction];

        return view('portfolio.show', compact('project', 'photos', 'photoSizes', 'group', 'direction', 'directionName'));
    }

    /**
     * Проверяет публичность группы и принадлежность подраздела этой группе.
     *
     * Допустимый ключ direction в чужой группе также возвращает 404, поэтому
     * у списка и проекта нет альтернативных адресов через соседние группы.
     * Проверка не обращается к базе: структура полностью задана конфигурацией.
     *
     * @param string $group Постоянный ключ группы из URL.
     * @param string|null $direction Ключ подраздела либо NULL для страницы группы.
     * @return array{name: string, image: string, public: bool, directions: array<string, string>} Настройки группы.
     */
    private function publicGroup(string $group, ?string $direction = null): array
    {
        $settings = config('portfolio.public_groups.'.$group);
        abort_unless(is_array($settings), 404);
        abort_if($direction !== null && ! array_key_exists($direction, $settings['directions']), 404);

        return $settings;
    }
}
