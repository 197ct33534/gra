<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetContract extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'code',
        'name',
        'starts_at',
        'ends_at',
        'discount_percent',
        'credit_limit',
        'payment_terms_days',
        'is_active',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'discount_percent' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @param  Builder<FleetContract>  $query
     * @return Builder<FleetContract>
     */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->whereDate('starts_at', '<=', $today)
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today);
            });
    }

    public function effectiveCreditLimit(): ?float
    {
        if ($this->credit_limit !== null) {
            return (float) $this->credit_limit;
        }

        return $this->customer?->credit_limit !== null
            ? (float) $this->customer->credit_limit
            : null;
    }
}
