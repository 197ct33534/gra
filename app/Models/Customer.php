<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'customer_type',
        'tax_code',
        'credit_limit',
        'payment_terms_days',
        'reminder_opt_out',
        'messaging_opt_in',
        'messaging_opt_in_at',
        'messaging_opt_in_source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'reminder_opt_out' => 'boolean',
            'messaging_opt_in' => 'boolean',
            'messaging_opt_in_at' => 'datetime',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function fleetContracts(): HasMany
    {
        return $this->hasMany(FleetContract::class);
    }

    public function activeFleetContract(): ?FleetContract
    {
        return $this->fleetContracts()->active()->latest('id')->first();
    }

    public function outstandingReceivable(): float
    {
        return (float) Invoice::query()
            ->whereHas('workOrder.vehicle', fn ($q) => $q->where('customer_id', $this->id))
            ->outstanding()
            ->get()
            ->sum(fn (Invoice $invoice): float => $invoice->outstandingAmount());
    }

    public function effectiveCreditLimit(): ?float
    {
        $contract = $this->activeFleetContract();

        if ($contract instanceof FleetContract && $contract->effectiveCreditLimit() !== null) {
            return $contract->effectiveCreditLimit();
        }

        return $this->credit_limit !== null ? (float) $this->credit_limit : null;
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->messaging_opt_in && $customer->messaging_opt_in_at === null) {
                $customer->messaging_opt_in_at = now();
                $customer->messaging_opt_in_source ??= 'manual';
            }

            if (! $customer->messaging_opt_in) {
                $customer->messaging_opt_in_at = null;
                $customer->messaging_opt_in_source = null;
            }
        });
    }
}
