<?php

namespace App\Models;

use App\Enums\EInvoiceStatus;
use App\Enums\InvoicePayer;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'invoice_number',
        'work_order_id',
        'payer_type',
        'payer_name',
        'total_amount',
        'paid_amount',
        'payment_method',
        'payment_status',
        'notes',
        'vat_rate',
        'amount_before_vat',
        'vat_amount',
        'einvoice_status',
        'einvoice_number',
        'einvoice_series',
        'einvoice_template',
        'einvoice_provider',
        'einvoice_provider_id',
        'einvoice_tax_authority_code',
        'einvoice_lookup_url',
        'einvoice_issued_at',
        'einvoice_canceled_at',
        'einvoice_error',
        'einvoice_payload',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payer_type' => 'customer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'payer_type' => InvoicePayer::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'vat_rate' => 'integer',
            'amount_before_vat' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'einvoice_status' => EInvoiceStatus::class,
            'einvoice_issued_at' => 'datetime',
            'einvoice_canceled_at' => 'datetime',
            'einvoice_payload' => 'array',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function payer(): InvoicePayer
    {
        return $this->payer_type ?? InvoicePayer::Customer;
    }

    public function isInsurancePayer(): bool
    {
        return $this->payer() === InvoicePayer::Insurance;
    }

    public function outstandingAmount(): float
    {
        return max(0.0, round((float) $this->total_amount - (float) $this->paid_amount, 2));
    }

    public static function statusFor(float $total, float $paid): PaymentStatus
    {
        if ($paid + 0.001 >= $total) {
            return PaymentStatus::Paid;
        }

        return $paid > 0 ? PaymentStatus::PartiallyPaid : PaymentStatus::Unpaid;
    }

    /**
     * Hóa đơn còn phải thu.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('payment_status', [PaymentStatus::Unpaid, PaymentStatus::PartiallyPaid]);
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    public function scopeForPayer(Builder $query, InvoicePayer $payer): void
    {
        $query->where('payer_type', $payer);
    }
}
