<?php

namespace App\Services;

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\Service;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(ActivityEvent $event, ?Model $subject, string $description, array $properties = []): ActivityLog
    {
        return ActivityLog::query()->create([
            'user_id' => auth()->id(),
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => self::label($subject),
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }

    private static function label(?Model $subject): ?string
    {
        return match (true) {
            $subject instanceof WorkOrder => $subject->code,
            $subject instanceof Invoice => $subject->invoice_number,
            $subject instanceof Part => $subject->sku,
            $subject instanceof Service => $subject->code,
            $subject instanceof PurchaseOrder, $subject instanceof GoodsReceipt => $subject->code,
            default => null,
        };
    }
}
