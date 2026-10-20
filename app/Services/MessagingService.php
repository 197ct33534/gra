<?php

namespace App\Services;

use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerMessageType;
use App\Enums\InvoicePayer;
use App\Enums\WorkOrderStatus;
use App\Messaging\MessageDeliveryException;
use App\Messaging\MessageGateway;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Invoice;
use App\Models\Vehicle;
use App\Models\VehicleRecommendation;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Giữ khách: nhắc bảo dưỡng đúng hạn từng xe và báo khách khi gara nhận được tiền.
 */
class MessagingService
{
    public function __construct(private readonly MessageGateway $gateway) {}

    /**
     * Xe đã đến hạn hoặc sắp đến hạn, không tính xe đang nằm xưởng.
     *
     * @return Collection<int, array{vehicle: Vehicle, status: string, due: ?string, last_reminder: ?CustomerMessage}>
     */
    public function dueVehicles(): Collection
    {
        $withinDays = (int) config('garage.maintenance.remind_before_days', 14);
        $timezone = (string) config('garage.timezone');

        return Vehicle::query()
            ->with(['customer', 'workOrders.items'])
            ->whereDoesntHave('workOrders', fn (Builder $query): Builder => $query->where('status', '!=', WorkOrderStatus::Delivered))
            ->get()
            ->map(function (Vehicle $vehicle) use ($withinDays, $timezone): ?array {
                $status = $vehicle->reminderStatus($withinDays);

                if ($status === null) {
                    return null;
                }

                return [
                    'vehicle' => $vehicle,
                    'status' => $status,
                    'due' => $vehicle->predictedServiceDate()?->timezone($timezone)->format('d/m/Y'),
                    'last_reminder' => CustomerMessage::query()
                        ->where('vehicle_id', $vehicle->id)
                        ->where('type', CustomerMessageType::MaintenanceReminder)
                        ->latest('id')
                        ->first(),
                ];
            })
            ->filter()
            ->sortBy(fn (array $row): string => ($row['status'] === 'overdue' ? '0' : '1').$row['vehicle']->license_plate)
            ->values();
    }

    public function queueMaintenanceReminder(Vehicle $vehicle, ?int $userId = null): CustomerMessage
    {
        $vehicle->loadMissing('customer');
        $customer = $vehicle->customer;

        if (! $customer instanceof Customer) {
            throw new DomainException("Xe {$vehicle->license_plate} chưa có chủ xe.");
        }

        if ($customer->reminder_opt_out) {
            throw new DomainException("Khách {$customer->name} đã từ chối nhận tin nhắc.");
        }

        if (! $customer->messaging_opt_in) {
            throw new DomainException("Khách {$customer->name} chưa đồng ý nhận tin (opt-in).");
        }

        $phone = self::normalizePhone($customer->phone);

        if ($phone === null) {
            throw new DomainException("Số điện thoại của {$customer->name} không hợp lệ.");
        }

        $againAfter = (int) config('garage.maintenance.remind_again_after_days', 30);
        $recent = CustomerMessage::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('type', CustomerMessageType::MaintenanceReminder)
            ->whereIn('status', [CustomerMessageStatus::Pending, CustomerMessageStatus::Sent])
            ->where('created_at', '>=', now()->subDays($againAfter))
            ->exists();

        if ($recent) {
            throw new DomainException("Xe {$vehicle->license_plate} đã được nhắc trong {$againAfter} ngày gần đây.");
        }

        return CustomerMessage::query()->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'type' => CustomerMessageType::MaintenanceReminder,
            'channel' => (string) config('garage.messaging.channel', 'zalo'),
            'phone' => $phone,
            'body' => $this->reminderBody($vehicle),
            'status' => CustomerMessageStatus::Pending,
            'scheduled_at' => now(),
            'created_by' => $userId,
        ]);
    }

    public function queuePaymentReceipt(Invoice $invoice, float $received): ?CustomerMessage
    {
        $invoice->loadMissing('workOrder.vehicle.customer');
        $customer = $invoice->workOrder?->vehicle?->customer;
        $phone = self::normalizePhone($customer?->phone);

        if (! $customer instanceof Customer || $phone === null || $invoice->payer_type === InvoicePayer::Insurance) {
            return null;
        }

        $outstanding = $invoice->outstandingAmount();
        $body = config('garage.name').' đã nhận '.VietnameseMoney::format($received)
            .' cho hóa đơn '.$invoice->invoice_number.' (xe '.$invoice->workOrder?->vehicle?->license_plate.'). '
            .($outstanding > 0 ? 'Còn lại: '.VietnameseMoney::format($outstanding).'.' : 'Đã thanh toán đủ.')
            .' Cảm ơn quý khách!';

        return CustomerMessage::query()->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $invoice->workOrder?->vehicle_id,
            'invoice_id' => $invoice->id,
            'type' => CustomerMessageType::PaymentReceipt,
            'channel' => (string) config('garage.messaging.channel', 'zalo'),
            'phone' => $phone,
            'body' => $body,
            'status' => CustomerMessageStatus::Pending,
            'scheduled_at' => now(),
        ]);
    }

    /**
     * Gửi các tin đến giờ; lỗi một tin không chặn các tin khác.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendPending(int $limit = 50): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $messages = CustomerMessage::query()
            ->where('status', CustomerMessageStatus::Pending)
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            $this->deliver($message) ? $result['sent']++ : $result['failed']++;
        }

        return $result;
    }

    public function deliver(CustomerMessage $message): bool
    {
        $maxAttempts = max(1, (int) config('garage.messaging_retry.max_attempts', 3));
        $message->attempts = (int) $message->attempts + 1;
        $message->last_attempt_at = now();

        try {
            $message->provider_message_id = $this->gateway->send($message);
            $message->status = CustomerMessageStatus::Sent;
            $message->sent_at = now();
            $message->error = null;
            $message->save();

            return true;
        } catch (MessageDeliveryException $exception) {
            $message->error = mb_substr($exception->getMessage(), 0, 250);

            if ($message->attempts >= $maxAttempts) {
                $message->status = CustomerMessageStatus::Failed;
            } else {
                // Giữ pending để lần schedule sau thử lại.
                $message->status = CustomerMessageStatus::Pending;
                $message->scheduled_at = now()->addMinutes(5 * $message->attempts);
            }

            $message->save();

            return false;
        }
    }

    public function retry(CustomerMessage $message): CustomerMessage
    {
        if ($message->status !== CustomerMessageStatus::Failed
            && $message->status !== CustomerMessageStatus::Pending) {
            throw new DomainException('Chỉ gửi lại tin đang chờ hoặc thất bại.');
        }

        $message->status = CustomerMessageStatus::Pending;
        $message->scheduled_at = now();
        $message->error = null;
        $message->save();

        $this->deliver($message->fresh() ?? $message);

        return $message->fresh() ?? $message;
    }

    public function reminderBody(Vehicle $vehicle): string
    {
        $timezone = (string) config('garage.timezone');
        $customer = $vehicle->customer;
        $due = $vehicle->predictedServiceDate();
        $nextOdo = $vehicle->next_service_odo;
        $car = trim($vehicle->license_plate.' ('.trim($vehicle->brand.' '.$vehicle->model).')');
        $when = $vehicle->isMaintenanceDue() ? 'đã đến hạn bảo dưỡng' : 'sắp đến hạn bảo dưỡng';
        $milestones = collect([
            $nextOdo !== null ? 'mốc '.number_format($nextOdo, 0, ',', '.').' km' : null,
            $due !== null ? 'khoảng ngày '.$due->timezone($timezone)->format('d/m/Y') : null,
        ])->filter()->implode(' hoặc ');
        $declined = $vehicle->recommendations()->open()->latest('declined_at')->limit(3)->get()
            ->map(fn (VehicleRecommendation $item): string => $item->item_name)
            ->implode(', ');

        return config('garage.name').' kính chào '.($customer?->name ?? 'quý khách').'. Xe '.$car.' '.$when
            .($milestones !== '' ? ' ('.$milestones.')' : '').'.'
            .($declined !== '' ? ' Lần trước gara khuyến nghị: '.$declined.'.' : '')
            .' Đặt lịch: '.config('garage.hotline').'.';
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '84')) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/^0\d{9}$/', $digits) === 1 ? $digits : null;
    }
}
