<?php

namespace App\Models;

use App\Enums\WarrantyClaimStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'warranty_card_id',
        'work_order_id',
        'symptom',
        'resolution',
        'status',
        'decided_by',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WarrantyClaimStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(WarrantyCard::class, 'warranty_card_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
