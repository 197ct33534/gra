<?php

namespace App\Observers;

use App\Enums\ActivityEvent;
use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Services\ActivityLogger;
use App\Support\VietnameseMoney;

class WorkOrderObserver
{
    public function updated(WorkOrder $order): void
    {
        if ($order->wasChanged('discount_amount')) {
            ActivityLogger::log(
                ActivityEvent::DiscountChanged,
                $order,
                'Giảm giá toàn đơn: '.VietnameseMoney::format($order->getOriginal('discount_amount')).' → '.VietnameseMoney::format($order->discount_amount),
                ['from' => (float) $order->getOriginal('discount_amount'), 'to' => (float) $order->discount_amount],
            );
        }

        if ($order->wasChanged('status')) {
            $from = WorkOrderStatus::tryFrom((string) ($order->getRawOriginal('status') ?? ''));

            ActivityLogger::log(
                ActivityEvent::StatusChanged,
                $order,
                ($from?->getLabel() ?? '—').' → '.$order->status->getLabel(),
                ['from' => $from?->value, 'to' => $order->status->value],
            );
        }
    }

    public function deleted(WorkOrder $order): void
    {
        ActivityLogger::log(
            ActivityEvent::OrderDeleted,
            $order,
            "Xóa lệnh {$order->code} (".$order->status->getLabel().', '.VietnameseMoney::format($order->final_amount).')',
            ['final_amount' => (float) $order->final_amount, 'status' => $order->status->value],
        );
    }
}
