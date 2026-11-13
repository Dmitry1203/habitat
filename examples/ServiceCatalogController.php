<?php

namespace App\Http\Controllers;

use App\Models\ServiceSection;
use Illuminate\View\View;

class ServiceCatalogController extends Controller
{
    /**
     * Показывает публичный список основных разделов услуг.
     *
     * Выбирает только верхний уровень с заполненным slug. Подразделы не имеют
     * собственных публичных страниц. Порядок совпадает с административным:
     * sort_order, затем ID. Все разделы выводятся сразу, без пагинации.
     *
     * @return View Шаблон services.index с коллекцией основных разделов.
     */
    public function index(): View
    {
        $sections = ServiceSection::query()->whereNull('parent_id')
            ->whereNotNull('slug')->where('slug', '!=', '')
            ->orderBy('sort_order')->orderBy('id')->get();

        return view('services.index', compact('sections'));
    }

    /**
     * Показывает основной раздел по slug со всеми подразделами и их услугами.
     *
     * Ограничение parent_id = NULL исключает открытие подраздела как основного
     * раздела. Связи children и services уже задают сортировку sort_order, ID.
     * Eager loading загружает содержимое без отдельного запроса для каждого
     * подраздела. Цены остаются необязательными строками, без преобразования.
     * Список основных разделов выбирается отдельно для навигации по видам работ
     * в том же порядке, что на странице /services и в административной части.
     *
     * @param string $slug Уникальная ссылка основного раздела из маршрута.
     * @return View Шаблон services.show с разделом и готовыми связями.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если основной раздел не найден.
     */
    public function show(string $slug): View
    {
        $section = ServiceSection::query()->whereNull('parent_id')->where('slug', $slug)
            ->with('children.services')->firstOrFail();

        $sections = ServiceSection::query()->whereNull('parent_id')
            ->whereNotNull('slug')->where('slug', '!=', '')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug']);

        return view('services.show', compact('section', 'sections'));
    }
}
