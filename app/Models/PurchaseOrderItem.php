<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_order_id',
        'part_id',
        'quantity_ordered',
        'quantity_received',
        'unit_cost',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:2',
            'quantity_received' => 'decimal:2',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function lineTotal(): float
    {
        return round((float) $this->quantity_ordered * (float) $this->unit_cost, 2);
    }

    public function remainingQuantity(): float
    {
        return max(0.0, round((float) $this->quantity_ordered - (float) $this->quantity_received, 2));
    }
}
