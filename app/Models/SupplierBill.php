<?php

namespace App\Models;

use App\Enums\SupplierBillStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBill extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'goods_receipt_id',
        'bill_number',
        'bill_date',
        'due_date',
        'vat_rate',
        'amount_before_vat',
        'vat_amount',
        'total_amount',
        'paid_amount',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'vat_rate' => 'integer',
            'amount_before_vat' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'status' => SupplierBillStatus::class,
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function outstandingAmount(): float
    {
        return max(0.0, round((float) $this->total_amount - (float) $this->paid_amount, 2));
    }

    /**
     * @param  Builder<SupplierBill>  $query
     * @return Builder<SupplierBill>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SupplierBillStatus::Unpaid->value,
            SupplierBillStatus::PartiallyPaid->value,
        ]);
    }

    public function refreshPaymentStatus(): void
    {
        $this->status = SupplierBillStatus::for((float) $this->total_amount, (float) $this->paid_amount);
        $this->save();
    }
}
