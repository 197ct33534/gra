<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerMessageType;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    public function __construct(private readonly QuickReceptionService $reception) {}

    public function nextCode(): string
    {
        return DB::transaction(function (): string {
            $prefix = 'LH-'.now()->format('Ymd').'-';

            $last = Appointment::query()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $sequence = 1;

            if (is_string($last) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $last, $m) === 1) {
                $sequence = ((int) $m[1]) + 1;
            }

            if ($sequence > 9999) {
                throw new DomainException('Đã hết mã lịch hẹn trong ngày.');
            }

            return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId): Appointment
    {
        return DB::transaction(function () use ($data, $userId): Appointment {
            $start = Carbon::parse($data['scheduled_start']);
            $end = isset($data['scheduled_end'])
                ? Carbon::parse($data['scheduled_end'])
                : $start->copy()->addMinutes((int) config('garage.appointments.default_duration_minutes', 60));

            if ($end->lte($start)) {
                throw new DomainException('Giờ kết thúc phải sau giờ bắt đầu.');
            }

            $bay = filled($data['lift_bay'] ?? null) ? (string) $data['lift_bay'] : null;
            $this->assertBayAvailable($bay, $start, $end);

            return Appointment::query()->create([
                'code' => $this->nextCode(),
                'customer_id' => $data['customer_id'] ?? null,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'walk_in_name' => filled($data['walk_in_name'] ?? null) ? trim((string) $data['walk_in_name']) : null,
                'walk_in_phone' => filled($data['walk_in_phone'] ?? null) ? trim((string) $data['walk_in_phone']) : null,
                'walk_in_plate' => filled($data['walk_in_plate'] ?? null)
                    ? trim((string) $data['walk_in_plate'])
                    : null,
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'lift_bay' => $bay,
                'service_advisor_id' => $data['service_advisor_id'] ?? $userId,
                'estimated_services' => $data['estimated_services'] ?? null,
                'customer_request' => filled($data['customer_request'] ?? null) ? trim((string) $data['customer_request']) : null,
                'status' => AppointmentStatus::Scheduled,
                'created_by' => $userId,
            ]);
        });
    }

    public function assertBayAvailable(?string $bay, Carbon $start, Carbon $end, ?int $ignoreId = null): void
    {
        if ($bay === null || $bay === '') {
            return;
        }

        $buffer = (int) config('garage.appointments.bay_buffer_minutes', 15);
        $windowStart = $start->copy()->subMinutes($buffer);
        $windowEnd = $end->copy()->addMinutes($buffer);

        $conflict = Appointment::query()
            ->blockingBay()
            ->where('lift_bay', $bay)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->first(function (Appointment $other) use ($windowStart, $windowEnd): bool {
                $otherStart = $other->scheduled_start;
                $otherEnd = $other->endsAt();

                return $otherStart->lt($windowEnd) && $otherEnd->gt($windowStart);
            });

        if ($conflict instanceof Appointment) {
            throw new DomainException(
                "Cầu \"{$bay}\" trùng lịch {$conflict->code} ("
                .$conflict->scheduled_start->timezone((string) config('garage.timezone'))->format('d/m H:i')
                .'–'.$conflict->endsAt()->timezone((string) config('garage.timezone'))->format('H:i').').'
            );
        }
    }

    public function cancel(Appointment $appointment, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $reason): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AppointmentStatus::Scheduled) {
                throw new DomainException('Chỉ hủy được lịch đang ở trạng thái Đã hẹn.');
            }

            $locked->status = AppointmentStatus::Canceled;
            $locked->canceled_reason = filled($reason) ? trim($reason) : null;
            $locked->save();

            return $locked;
        });
    }

    public function markNoShow(Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($appointment): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AppointmentStatus::Scheduled) {
                throw new DomainException('Chỉ đánh dấu không đến với lịch Đã hẹn.');
            }

            $locked->status = AppointmentStatus::NoShow;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Xe đã tới: tạo lệnh tiếp nhận và gắn vào lịch.
     */
    public function checkIn(Appointment $appointment, int $userId, int|string|null $receptionOdo = null): WorkOrder
    {
        return DB::transaction(function () use ($appointment, $userId, $receptionOdo): WorkOrder {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AppointmentStatus::Scheduled) {
                throw new DomainException('Lịch không còn ở trạng thái Đã hẹn.');
            }

            if ($locked->work_order_id) {
                throw new DomainException('Lịch đã gắn lệnh '.$locked->workOrder?->code.'.');
            }

            $plate = $locked->displayPlate();

            if (! filled($plate)) {
                throw new DomainException('Lịch hẹn chưa có biển số xe.');
            }

            $vehicle = $locked->vehicle;
            $odo = $receptionOdo !== null
                ? (int) $receptionOdo
                : (int) ($vehicle?->current_odo ?? 0);

            if ($odo <= 0 && $vehicle instanceof Vehicle) {
                $odo = max(1, (int) $vehicle->current_odo);
            }

            if ($odo <= 0) {
                throw new DomainException('Cần nhập ODO tiếp nhận khi check-in.');
            }

            $order = $this->reception->receive([
                'license_plate' => $plate,
                'phone' => $locked->displayPhone(),
                'customer_name' => $locked->displayName(),
                'brand' => $vehicle?->brand,
                'model' => $vehicle?->model,
                'reception_odo' => $odo,
                'customer_request' => $locked->customer_request,
                'promised_at' => $locked->endsAt(),
                'lift_bay' => $locked->lift_bay,
                'package_ids' => collect($locked->estimated_services ?? [])
                    ->pluck('service_package_id')
                    ->filter()
                    ->values()
                    ->all(),
            ], $userId);

            $locked->work_order_id = $order->id;
            $locked->status = AppointmentStatus::CheckedIn;
            $locked->customer_id ??= $order->vehicle?->customer_id;
            $locked->vehicle_id ??= $order->vehicle_id;
            $locked->save();

            return $order;
        });
    }

    /**
     * Queue tin nhắc cho lịch sắp tới (trong cửa sổ remind_before_hours).
     *
     * @return array{queued: int, skipped: int}
     */
    public function queueUpcomingReminders(): array
    {
        $hours = (int) config('garage.appointments.remind_before_hours', 24);
        $from = now();
        $to = now()->addHours($hours);
        $result = ['queued' => 0, 'skipped' => 0];

        $appointments = Appointment::query()
            ->with(['customer', 'vehicle.customer'])
            ->where('status', AppointmentStatus::Scheduled)
            ->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_start', [$from, $to])
            ->get();

        foreach ($appointments as $appointment) {
            try {
                $this->queueReminder($appointment);
                $result['queued']++;
            } catch (DomainException) {
                $result['skipped']++;
            }
        }

        return $result;
    }

    public function queueReminder(Appointment $appointment): CustomerMessage
    {
        $appointment->loadMissing(['customer', 'vehicle.customer']);

        if ($appointment->status !== AppointmentStatus::Scheduled) {
            throw new DomainException('Chỉ nhắc lịch đang Đã hẹn.');
        }

        if ($appointment->reminder_sent_at !== null) {
            throw new DomainException('Lịch '.$appointment->code.' đã nhắc rồi.');
        }

        $customer = $appointment->customer ?? $appointment->vehicle?->customer;
        $phone = MessagingService::normalizePhone($appointment->displayPhone() ?? $customer?->phone);

        if ($phone === null) {
            throw new DomainException('Lịch '.$appointment->code.' thiếu SĐT hợp lệ.');
        }

        if ($customer instanceof Customer && $customer->reminder_opt_out) {
            throw new DomainException('Khách từ chối nhận tin nhắc.');
        }

        // Tạo customer tạm nếu chỉ có walk-in phone — cần customer_id FK.
        if (! $customer instanceof Customer) {
            throw new DomainException('Lịch walk-in chưa gắn khách — bổ sung SĐT khách trong danh mục trước khi nhắc tự động.');
        }

        $tz = (string) config('garage.timezone');
        $when = $appointment->scheduled_start->timezone($tz)->format('d/m/Y H:i');
        $plate = $appointment->displayPlate() ?? 'xe';
        $body = config('garage.name').' nhắc lịch hẹn '.$when
            .' ('.$plate.'). '
            .(filled($appointment->lift_bay) ? 'Cầu: '.$appointment->lift_bay.'. ' : '')
            .'Hotline: '.config('garage.hotline').'.';

        return DB::transaction(function () use ($appointment, $customer, $phone, $body): CustomerMessage {
            $message = CustomerMessage::query()->create([
                'customer_id' => $customer->id,
                'vehicle_id' => $appointment->vehicle_id,
                'type' => CustomerMessageType::AppointmentReminder,
                'channel' => (string) config('garage.messaging.channel', 'zalo'),
                'phone' => $phone,
                'body' => $body,
                'status' => CustomerMessageStatus::Pending,
                'scheduled_at' => now(),
                'created_by' => null,
            ]);

            $appointment->reminder_sent_at = now();
            $appointment->save();

            return $message;
        });
    }
}
