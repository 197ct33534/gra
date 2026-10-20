<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'supplier_id',
        'status',
        'ordered_at',
        'expected_at',
        'notes',
        'created_by',
        'subtotal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'ordered_at' => 'datetime',
            'expected_at' => 'date',
            'subtotal' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function recalculateSubtotal(): void
    {
        $this->loadMissing('items');
        $this->subtotal = round((float) $this->items->sum(fn (PurchaseOrderItem $item): float => $item->lineTotal()), 2);
        $this->save();
    }

    public function refreshReceiveStatus(): void
    {
        $this->loadMissing('items');

        if ($this->status === PurchaseOrderStatus::Canceled || $this->status === PurchaseOrderStatus::Draft) {
            return;
        }

        $ordered = (float) $this->items->sum('quantity_ordered');
        $received = (float) $this->items->sum('quantity_received');

        if ($received <= 0) {
            $this->status = PurchaseOrderStatus::Ordered;
        } elseif ($received + 0.001 >= $ordered) {
            $this->status = PurchaseOrderStatus::Received;
        } else {
            $this->status = PurchaseOrderStatus::PartiallyReceived;
        }

        $this->save();
    }
}
