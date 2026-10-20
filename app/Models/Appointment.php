<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Helpers\LicensePlateHelper;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use BelongsToBranch;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'branch_id',
        'customer_id',
        'vehicle_id',
        'walk_in_name',
        'walk_in_phone',
        'walk_in_plate',
        'scheduled_start',
        'scheduled_end',
        'lift_bay',
        'service_advisor_id',
        'estimated_services',
        'customer_request',
        'status',
        'work_order_id',
        'canceled_reason',
        'reminder_sent_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'estimated_services' => 'array',
            'status' => AppointmentStatus::class,
            'reminder_sent_at' => 'datetime',
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

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_advisor_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function endsAt(): Carbon
    {
        if ($this->scheduled_end instanceof Carbon) {
            return $this->scheduled_end;
        }

        $minutes = (int) config('garage.appointments.default_duration_minutes', 60);

        return $this->scheduled_start->copy()->addMinutes($minutes);
    }

    public function displayName(): string
    {
        return $this->customer?->name
            ?? $this->walk_in_name
            ?? 'Khách hẹn';
    }

    public function displayPhone(): ?string
    {
        return $this->customer?->phone ?? $this->walk_in_phone;
    }

    public function displayPlate(): ?string
    {
        return $this->vehicle?->license_plate ?? $this->walk_in_plate;
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeBlockingBay(Builder $query): Builder
    {
        return $query->where('status', AppointmentStatus::Scheduled);
    }

    public static function normalizePlate(?string $plate): ?string
    {
        $clean = LicensePlateHelper::clean($plate);

        return $clean !== '' ? $clean : null;
    }
}
