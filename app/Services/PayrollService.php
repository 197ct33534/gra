<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Lương khoán thợ: hưởng theo hạng mục công mình được gán trên các xe đã bàn giao.
 *
 * Thứ tự ưu tiên: khoán cố định theo dịch vụ → % riêng của thợ → % mặc định của gara.
 * Không tính khoán: phụ tùng, gia công ngoài, hạng mục khách từ chối, lệnh bảo hành làm lại.
 */
class PayrollService
{
    public static function defaultRate(): float
    {
        return (float) config('garage.default_commission_percent', 30);
    }

    public static function rateFor(?User $technician): float
    {
        return $technician?->commission_rate !== null ? (float) $technician->commission_rate : self::defaultRate();
    }

    public function payoutFor(WorkOrderItem $item, WorkOrder $order, ?Service $service = null, ?User $technician = null): float
    {
        if ($item->item_type !== WorkOrderItemType::Service
            || $item->technician_id === null
            || $item->is_approved === false
            || $item->source() === WorkOrderItemSource::Subcontract
            || $order->order_type === WorkOrderType::WarrantyRedo) {
            return 0.0;
        }

        $service ??= Service::query()->find($item->item_id);

        if ($service instanceof Service && $service->technician_payout !== null) {
            return round((float) $service->technician_payout * (float) $item->quantity, 2);
        }

        $technician ??= User::query()->find($item->technician_id);

        return round((float) $item->subtotal * self::rateFor($technician) / 100, 2);
    }

    /**
     * Chốt tiền khoán từng dòng lúc bàn giao xe.
     */
    public function snapshot(WorkOrder $order): void
    {
        $items = $order->items()->get();
        $services = Service::query()->whereIn('id', $items->where('item_type', WorkOrderItemType::Service)->pluck('item_id'))->get()->keyBy('id');
        $technicians = User::query()->whereIn('id', $items->pluck('technician_id')->filter())->get()->keyBy('id');

        foreach ($items as $item) {
            $item->technician_payout = $this->payoutFor($item, $order, $services->get($item->item_id), $technicians->get($item->technician_id));
            $item->saveQuietly();
        }
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public static function monthRange(string $month): array
    {
        $timezone = (string) config('garage.timezone');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', $timezone)->startOfMonth();

        return ['start' => $start->copy()->utc(), 'end' => $start->copy()->endOfMonth()->utc()];
    }

    /**
     * Hạng mục công đã bàn giao trong tháng có gán thợ.
     *
     * @return Collection<int, WorkOrderItem>
     */
    public function deliveredLaborItems(string $month, ?int $technicianId = null): Collection
    {
        ['start' => $start, 'end' => $end] = self::monthRange($month);

        return WorkOrderItem::query()
            ->where('item_type', WorkOrderItemType::Service)
            ->whereNotNull('technician_id')
            ->where('is_approved', true)
            ->when($technicianId !== null, fn (Builder $query): Builder => $query->where('technician_id', $technicianId))
            ->whereHas('workOrder', fn (Builder $query): Builder => $query
                ->where('status', WorkOrderStatus::Delivered)
                ->whereBetween('delivered_at', [$start, $end]))
            ->with(['workOrder.vehicle', 'technician', 'service'])
            ->orderBy('work_order_id')
            ->get();
    }

    /**
     * Tiền khoán của dòng: số đã chốt, hoặc tính lại theo mức hiện hành cho lệnh bàn giao trước khi có tính năng này.
     */
    public function itemPayout(WorkOrderItem $item): float
    {
        return $item->technician_payout !== null
            ? (float) $item->technician_payout
            : $this->payoutFor($item, $item->workOrder, $item->service, $item->technician);
    }

    /**
     * @return list<array{technician: User, jobs: int, cars: int, labor: float, payout: float, minutes: int, actual_minutes: int, redo: int, rate: float}>
     */
    public function technicianSummary(string $month): array
    {
        $items = $this->deliveredLaborItems($month);
        $technicians = User::query()->where('role', UserRole::Technician)->orderBy('name')->get();
        $rows = [];

        foreach ($technicians as $technician) {
            $own = $items->where('technician_id', $technician->id);

            $rows[] = [
                'technician' => $technician,
                'jobs' => $own->count(),
                'cars' => $own->pluck('work_order_id')->unique()->count(),
                'labor' => round((float) $own->sum(fn (WorkOrderItem $item): float => (float) $item->subtotal), 2),
                'payout' => round((float) $own->sum(fn (WorkOrderItem $item): float => $this->itemPayout($item)), 2),
                'minutes' => (int) $own->sum(fn (WorkOrderItem $item): float => (float) $item->quantity * (int) ($item->service?->standard_time_minutes ?? 0)),
                'actual_minutes' => (int) $own->sum(fn (WorkOrderItem $item): int => (int) ($item->actual_minutes ?? 0)),
                'redo' => $own->filter(fn (WorkOrderItem $item): bool => $item->workOrder?->order_type === WorkOrderType::WarrantyRedo)->count(),
                'rate' => self::rateFor($technician),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['payout'] <=> $a['payout']);

        return $rows;
    }

    /**
     * Năng suất cầu nâng: số xe, tiền công, thời gian xe nằm trên cầu trung bình.
     *
     * @return list<array{lift: string, cars: int, labor: float, parts: float, avg_hours: float}>
     */
    public function liftSummary(string $month): array
    {
        ['start' => $start, 'end' => $end] = self::monthRange($month);
        $orders = WorkOrder::query()
            ->where('status', WorkOrderStatus::Delivered)
            ->whereBetween('delivered_at', [$start, $end])
            ->get(['id', 'lift_bay', 'total_labor_amount', 'total_parts_amount', 'created_at', 'delivered_at']);
        $rows = [];

        foreach ($orders->groupBy(fn (WorkOrder $order): string => $order->lift_bay ?: 'Chưa xếp cầu') as $lift => $group) {
            $hours = $group->map(fn (WorkOrder $order): float => $order->created_at !== null && $order->delivered_at !== null
                ? $order->created_at->diffInMinutes($order->delivered_at) / 60
                : 0.0);

            $rows[] = [
                'lift' => (string) $lift,
                'cars' => $group->count(),
                'labor' => round((float) $group->sum('total_labor_amount'), 2),
                'parts' => round((float) $group->sum('total_parts_amount'), 2),
                'avg_hours' => round((float) $hours->avg(), 1),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strnatcmp($a['lift'], $b['lift']));

        return $rows;
    }

    public function monthlyPayoutFor(User $technician, string $month): float
    {
        return round((float) $this->deliveredLaborItems($month, $technician->id)->sum(fn (WorkOrderItem $item): float => $this->itemPayout($item)), 2);
    }

    public static function currentMonth(): string
    {
        return Carbon::now((string) config('garage.timezone'))->format('Y-m');
    }
}
