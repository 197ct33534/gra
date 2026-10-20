<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'stocktake_id',
        'part_id',
        'system_quantity',
        'counted_quantity',
        'difference',
        'unit_cost',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'difference' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function varianceValue(): float
    {
        if ($this->difference === null) {
            return 0.0;
        }

        return round((float) $this->difference * (float) $this->unit_cost, 2);
    }
}
