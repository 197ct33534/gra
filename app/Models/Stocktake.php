<?php

namespace App\Models;

use App\Enums\StocktakeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stocktake extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'status',
        'counted_at',
        'note',
        'created_by',
        'applied_by',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StocktakeStatus::class,
            'counted_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function appliedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StocktakeItem::class);
    }

    public function varianceValue(): float
    {
        $this->loadMissing('items');

        return round((float) $this->items->sum(fn (StocktakeItem $item): float => $item->varianceValue()), 2);
    }
}
