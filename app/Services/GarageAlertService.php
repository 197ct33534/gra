<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\Appointment;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class GarageAlertService
{
    /**
     * @return Collection<int, WorkOrder>
     */
    public function lateWorkOrders(): Collection
    {
        return WorkOrder::query()
            ->with(['vehicle.customer'])
            ->whereNotNull('promised_at')
            ->where('promised_at', '<', now())
            ->whereNotIn('status', [
                WorkOrderStatus::Delivered,
                WorkOrderStatus::Completed,
            ])
            ->orderBy('promised_at')
            ->get();
    }

    /**
     * @return Collection<int, Appointment>
     */
    public function lateAppointments(): Collection
    {
        return Appointment::query()
            ->with(['customer', 'vehicle'])
            ->where('status', AppointmentStatus::Scheduled)
            ->where('scheduled_start', '<', now()->subMinutes(15))
            ->orderBy('scheduled_start')
            ->get();
    }

    /**
     * @return Collection<int, Part>
     */
    public function lowStockParts(): Collection
    {
        return Part::query()
            ->whereRaw('stock_quantity - reserved_quantity <= min_stock_alert')
            ->orderByRaw('stock_quantity - reserved_quantity')
            ->get();
    }

    /**
     * Gửi thông báo Filament (database) cho admin/cố vấn — mỗi key tối đa 1 lần / 12 giờ.
     *
     * @return array{late_ro: int, late_appt: int, low_stock: int}
     */
    public function dispatchDatabaseNotifications(): array
    {
        $recipients = User::query()
            ->whereIn('role', [UserRole::Admin, UserRole::ServiceAdvisor])
            ->get();

        $sent = ['late_ro' => 0, 'late_appt' => 0, 'low_stock' => 0];

        if ($recipients->isEmpty()) {
            return $sent;
        }

        foreach ($this->lateWorkOrders() as $order) {
            $key = 'late_ro:'.$order->id;
            if (! $this->claim($key)) {
                continue;
            }

            Notification::make()
                ->title('RO trễ hẹn: '.$order->code)
                ->body(
                    ($order->vehicle?->license_plate ?? '—')
                    .' hẹn '.optional($order->promised_at)->timezone((string) config('garage.timezone'))->format('d/m H:i')
                    .' — '.$order->status->getLabel()
                )
                ->warning()
                ->sendToDatabase($recipients);

            $sent['late_ro']++;
        }

        foreach ($this->lateAppointments() as $appt) {
            $key = 'late_appt:'.$appt->id;
            if (! $this->claim($key)) {
                continue;
            }

            Notification::make()
                ->title('Lịch hẹn trễ: '.$appt->code)
                ->body(
                    'Khách '.($appt->customer?->name ?? $appt->walk_in_name ?? '—')
                    .' lúc '.optional($appt->scheduled_start)->timezone((string) config('garage.timezone'))->format('d/m H:i')
                )
                ->danger()
                ->sendToDatabase($recipients);

            $sent['late_appt']++;
        }

        $low = $this->lowStockParts();
        if ($low->isNotEmpty()) {
            $key = 'low_stock:'.now()->format('Y-m-d-H');
            if ($this->claim($key)) {
                $names = $low->take(5)->map(fn (Part $p): string => $p->sku)->implode(', ');
                Notification::make()
                    ->title('Tồn thấp: '.$low->count().' mã')
                    ->body($names.($low->count() > 5 ? '…' : ''))
                    ->warning()
                    ->sendToDatabase($recipients);

                $sent['low_stock'] = $low->count();
            }
        }

        return $sent;
    }

    private function claim(string $key): bool
    {
        $cacheKey = 'garage_alert:'.$key;

        if (cache()->has($cacheKey)) {
            return false;
        }

        cache()->put($cacheKey, true, now()->addHours(12));

        return true;
    }
}
