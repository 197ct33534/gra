<?php

namespace App\Models;

use App\Enums\WorkOrderItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hạng mục gara khuyến cáo nhưng khách từ chối; dùng để tư vấn lại ở lần xe vào xưởng sau.
 */
class VehicleRecommendation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'vehicle_id',
        'work_order_id',
        'work_order_item_id',
        'item_type',
        'item_id',
        'item_name',
        'revision_no',
        'decline_reason',
        'odo',
        'declined_at',
        'resolved_at',
        'resolved_work_order_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => WorkOrderItemType::class,
            'item_id' => 'integer',
            'revision_no' => 'integer',
            'odo' => 'integer',
            'declined_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function resolvedWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'resolved_work_order_id');
    }

    /**
     * @param  Builder<VehicleRecommendation>  $query
     * @return Builder<VehicleRecommendation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
