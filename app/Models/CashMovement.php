<?php

namespace App\Models;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashMovement extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'cash_session_id',
        'direction',
        'amount',
        'category',
        'reference_type',
        'reference_id',
        'occurred_at',
        'created_by',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CashMovementDirection::class,
            'amount' => 'decimal:2',
            'category' => CashMovementCategory::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
