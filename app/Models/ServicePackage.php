<?php

namespace App\Models;

use App\Enums\WorkOrderItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePackage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'vehicle_hint',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServicePackageItem::class);
    }

    /**
     * @param  Builder<ServicePackage>  $query
     * @return Builder<ServicePackage>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Giá gói theo bảng giá hiện hành (chưa VAT).
     */
    public function catalogTotal(): float
    {
        $this->loadMissing('items');
        $parts = Part::query()->whereIn('id', $this->items->where('item_type', WorkOrderItemType::Part)->pluck('item_id'))->pluck('selling_price', 'id');
        $services = Service::query()->whereIn('id', $this->items->where('item_type', WorkOrderItemType::Service)->pluck('item_id'))->pluck('labor_cost', 'id');

        return round($this->items->sum(fn (ServicePackageItem $item): float => (float) $item->quantity * (float) ($item->item_type === WorkOrderItemType::Part
            ? ($parts[$item->item_id] ?? 0)
            : ($services[$item->item_id] ?? 0))), 2);
    }
}
