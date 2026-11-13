<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['section_id', 'name', 'price_label', 'sort_order'])]
class Service extends Model
{
    /**
     * Приводит ссылку на раздел и порядок сортировки к целым числам.
     *
     * price_label остаётся необязательным текстом, например «от 500 руб./м.п.»
     * или «Цена договорная». Числовые расчёты по этому полю не выполняются.
     *
     * @return array<string, string> Преобразования атрибутов услуги.
     */
    protected function casts(): array
    {
        return ['section_id' => 'integer', 'sort_order' => 'integer'];
    }

    /**
     * Возвращает раздел, под заголовком которого выводится услуга.
     *
     * Обязательный внешний ключ services.section_id ссылается на раздел.
     * Проверку того, что выбран именно второй уровень, нужно выполнять
     * в операциях сохранения услуги; сам внешний ключ проверяет существование.
     *
     * @return BelongsTo<ServiceSection, $this> Раздел текущей услуги.
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ServiceSection::class, 'section_id');
    }
}
