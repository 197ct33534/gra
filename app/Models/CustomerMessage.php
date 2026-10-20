<?php

namespace App\Models;

use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerMessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMessage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'vehicle_id',
        'invoice_id',
        'type',
        'channel',
        'phone',
        'body',
        'status',
        'provider_message_id',
        'error',
        'scheduled_at',
        'sent_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerMessageType::class,
            'status' => CustomerMessageStatus::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
