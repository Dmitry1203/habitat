<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'cover_path', 'direction'])]
class PortfolioProject extends Model
{
    /**
     * Задаёт приведение номера шаблона к целому числу при чтении модели.
     *
     * Eloquent сохраняет NULL без преобразования: это признак того, что
     * шаблон ещё не выбран. Целый тип нужен для обращения к конфигурации сеток.
     *
     * @return array<string, string> Преобразования атрибутов модели.
     */
    protected function casts(): array
    {
        return ['grid_template' => 'integer'];
    }

    /**
     * Выбирает slug вместо ID для поиска проекта через привязку маршрута.
     *
     * Это же поле используется при построении ссылок с передачей модели
     * в route(). Уникальность значения обеспечивается индексом базы.
     *
     * @return string Имя атрибута, используемого в URL проекта.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Находит проект по slug только внутри направления текущего маршрута.
     *
     * Laravel вызывает метод до контроллера для всех маршрутов с моделью
     * PortfolioProject: форм, удаления, сеток, загрузки, оригиналов и сортировки.
     * Подмена direction при известном slug другого направления возвращает NULL
     * и стандартный ответ 404. Отсутствие direction также не даёт совпадения:
     * поле базы NOT NULL. Проверка действует и при включённом кеше маршрутов.
     *
     * @param mixed $value Значение slug из адреса проекта.
     * @param string|null $field Явное поле привязки или NULL для getRouteKeyName().
     * @return PortfolioProject|null Проект выбранного направления либо NULL для ответа 404.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->resolveRouteBindingQuery($this, $value, $field)
            ->where('direction', request()->route('direction'))->first();
    }

    /**
     * Возвращает связь проекта с записями фотографий его галереи.
     *
     * Внешний ключ задан явно: portfolio_photos.project_id. Номер ячейки
     * хранится в cell; на уровне базы пара project_id + cell уникальна.
     * Связь используется для выборки, создания фото и счётчика withCount.
     *
     * @return HasMany<PortfolioPhoto, $this> Связь с фотографиями текущего проекта.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(PortfolioPhoto::class, 'project_id');
    }
}
