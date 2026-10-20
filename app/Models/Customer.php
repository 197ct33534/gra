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
            'reminder_opt_out' => 'boolean',
            'messaging_opt_in' => 'boolean',
            'messaging_opt_in_at' => 'datetime',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
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
