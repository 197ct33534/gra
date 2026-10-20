<?php

namespace App\Observers;

use App\Enums\ActivityEvent;
use App\Enums\WorkOrderItemSource;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ActivityLogger;
use App\Support\VietnameseMoney;

/**
 * Ghi lại thao tác giá trên từng dòng báo giá: nơi dễ "ăn chênh" nhất.
 */
class WorkOrderItemObserver
{
    public function created(WorkOrderItem $item): void
    {
        $this->logIfBelowCatalog($item);
    }

    public function updated(WorkOrderItem $item): void
    {
        $order = $this->order($item);
        $name = $item->catalogName();

        if ($item->wasChanged('unit_price')) {
            ActivityLogger::log(
                ActivityEvent::PriceChanged,
                $order,
                "Sửa đơn giá {$name}: ".VietnameseMoney::format($item->getOriginal('unit_price')).' → '.VietnameseMoney::format($item->unit_price),
                ['item_id' => $item->id, 'from' => (float) $item->getOriginal('unit_price'), 'to' => (float) $item->unit_price],
            );
            $this->logIfBelowCatalog($item);
        }

        if ($item->wasChanged('discount_amount')) {
            ActivityLogger::log(
                ActivityEvent::DiscountChanged,
                $order,
                "Giảm giá dòng {$name}: ".VietnameseMoney::format($item->getOriginal('discount_amount')).' → '.VietnameseMoney::format($item->discount_amount),
                ['item_id' => $item->id, 'from' => (float) $item->getOriginal('discount_amount'), 'to' => (float) $item->discount_amount],
            );
        }

        if ($item->wasChanged('is_approved') && $item->is_approved === false) {
            ActivityLogger::log(
                ActivityEvent::ItemDeclined,
                $order,
                "Khách từ chối {$name}".(filled($item->decline_reason) ? ': '.$item->decline_reason : ''),
                ['item_id' => $item->id, 'amount' => $item->quotedAmount()],
            );
        }
    }

    public function deleted(WorkOrderItem $item): void
    {
        ActivityLogger::log(
            ActivityEvent::ItemDeleted,
            $this->order($item),
            'Xóa hạng mục '.$item->catalogName().' × '.(float) $item->quantity.' ('.VietnameseMoney::format($item->subtotal).')',
            ['item_id' => $item->id, 'subtotal' => (float) $item->subtotal],
        );
    }

    private function logIfBelowCatalog(WorkOrderItem $item): void
    {
        if ($item->source() !== WorkOrderItemSource::Stock) {
            return;
        }

        $catalog = $item->catalogPrice();
        $price = (float) $item->unit_price;

        if ($catalog <= 0 || $price >= $catalog) {
            return;
        }

        $percent = round(($catalog - $price) / $catalog * 100, 1);

        ActivityLogger::log(
            ActivityEvent::BelowCatalog,
            $this->order($item),
            $item->catalogName().': bán '.VietnameseMoney::format($price).', bảng giá '.VietnameseMoney::format($catalog)." (thấp hơn {$percent}%)",
            ['item_id' => $item->id, 'catalog' => $catalog, 'price' => $price, 'percent' => $percent],
        );
    }

    private function order(WorkOrderItem $item): ?WorkOrder
    {
        return WorkOrder::query()->find($item->work_order_id);
    }
}
