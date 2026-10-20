<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianTimeLog extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'technician_id',
        'work_order_id',
        'work_order_item_id',
        'started_at',
        'ended_at',
        'minutes',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'minutes' => 'integer',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @param  Builder<TechnicianTimeLog>  $query
     * @return Builder<TechnicianTimeLog>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
