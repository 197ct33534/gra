<?php

namespace App\Models;

use App\Enums\WarrantyCardStatus;
use App\Enums\WorkOrderItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class WarrantyCard extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'vehicle_id',
        'customer_id',
        'work_order_id',
        'work_order_item_id',
        'item_type',
        'item_id',
        'item_name',
        'starts_at',
        'ends_at',
        'start_odo',
        'end_odo',
        'status',
        'terms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => WorkOrderItemType::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'start_odo' => 'integer',
            'end_odo' => 'integer',
            'status' => WarrantyCardStatus::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    public function isValidAt(?Carbon $date = null, ?int $odo = null): bool
    {
        if ($this->status !== WarrantyCardStatus::Active) {
            return false;
        }

        $date ??= now();

        if ($this->ends_at instanceof Carbon && $date->startOfDay()->gt($this->ends_at->copy()->endOfDay())) {
            return false;
        }

        if ($odo !== null && $this->end_odo !== null && $odo > $this->end_odo) {
            return false;
        }

        return true;
    }

    /**
     * @param  Builder<WarrantyCard>  $query
     * @return Builder<WarrantyCard>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', WarrantyCardStatus::Active);
    }

    /**
     * @param  Builder<WarrantyCard>  $query
     * @return Builder<WarrantyCard>
     */
    public function scopeExpiringSoon(Builder $query, int $withinDays = 30): Builder
    {
        return $query->active()
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now()->toDateString(), now()->addDays($withinDays)->toDateString()]);
    }
}
