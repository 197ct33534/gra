<?php

namespace App\Observers;

use App\Enums\ActivityEvent;
use App\Models\Part;
use App\Models\Service;
use App\Services\ActivityLogger;
use App\Support\VietnameseMoney;
use Illuminate\Database\Eloquent\Model;

/**
 * Bảng giá phụ tùng / tiền công bị sửa: ghi lại ai đổi, từ bao nhiêu sang bao nhiêu.
 */
class CatalogPriceObserver
{
    public function updated(Model $model): void
    {
        $fields = match (true) {
            $model instanceof Part => ['selling_price' => 'giá bán', 'cost_price' => 'giá vốn'],
            $model instanceof Service => ['labor_cost' => 'tiền công'],
            default => [],
        };

        foreach ($fields as $field => $label) {
            if (! $model->wasChanged($field)) {
                continue;
            }

            ActivityLogger::log(
                ActivityEvent::CatalogPriceChanged,
                $model,
                "Sửa {$label} {$model->getAttribute('name')}: ".VietnameseMoney::format($model->getOriginal($field)).' → '.VietnameseMoney::format($model->getAttribute($field)),
                ['field' => $field, 'from' => (float) $model->getOriginal($field), 'to' => (float) $model->getAttribute($field)],
            );
        }
    }
}
