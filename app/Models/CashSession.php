<?php

namespace App\Models;

use App\Enums\CashMovementDirection;
use App\Enums\CashSessionStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    use BelongsToBranch;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'opened_at',
        'closed_at',
        'opened_by',
        'closed_by',
        'opening_balance',
        'closing_counted',
        'expected_balance',
        'difference',
        'status',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_balance' => 'decimal:2',
            'closing_counted' => 'decimal:2',
            'expected_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'status' => CashSessionStatus::class,
        ];
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * @param  Builder<CashSession>  $query
     * @return Builder<CashSession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CashSessionStatus::Open);
    }

    public function computedExpectedBalance(): float
    {
        $this->loadMissing('movements');

        $in = (float) $this->movements
            ->where('direction', CashMovementDirection::In)
            ->sum('amount');
        $out = (float) $this->movements
            ->where('direction', CashMovementDirection::Out)
            ->sum('amount');

        return round((float) $this->opening_balance + $in - $out, 2);
    }
}
