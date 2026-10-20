<?php

namespace App\Models;

use App\Enums\WorkOrderItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'labor_cost',
        'technician_payout',
        'standard_time_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'labor_cost' => 'decimal:2',
            'technician_payout' => 'decimal:2',
            'standard_time_minutes' => 'integer',
        ];
    }

    public function workOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'item_id')
            ->where('work_order_items.item_type', WorkOrderItemType::Service->value);
    }
}
