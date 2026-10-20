<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GoodsReceipt extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'supplier_id',
        'purchase_order_id',
        'received_at',
        'invoice_no_supplier',
        'invoice_date',
        'vat_rate',
        'amount_before_vat',
        'vat_amount',
        'total_amount',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'invoice_date' => 'date',
            'vat_rate' => 'integer',
            'amount_before_vat' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function bill(): HasOne
    {
        return $this->hasOne(SupplierBill::class);
    }
}
