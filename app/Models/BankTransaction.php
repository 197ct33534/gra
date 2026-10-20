<?php

namespace App\Models;

use App\Enums\BankTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Giao dịch tiền vào tài khoản gara do ngân hàng / SePay báo về.
 */
class BankTransaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'provider_id',
        'bank',
        'account_number',
        'amount',
        'content',
        'reference_code',
        'transacted_at',
        'status',
        'invoice_id',
        'matched_amount',
        'note',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'matched_amount' => 'decimal:2',
            'transacted_at' => 'datetime',
            'status' => BankTransactionStatus::class,
            'payload' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
