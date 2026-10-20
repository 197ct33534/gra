<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\TechnicianTimeLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TimeClockService
{
    /**
     * @return list<WorkOrderStatus>
     */
    public static function clockableStatuses(): array
    {
        return [
            WorkOrderStatus::CustomerApproved,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::Qc,
        ];
    }

    public function openLogFor(User $technician): ?TechnicianTimeLog
    {
        return TechnicianTimeLog::query()
            ->open()
            ->where('technician_id', $technician->id)
            ->with(['workOrder.vehicle', 'workOrderItem'])
            ->first();
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    public function availableOrdersFor(User $technician): Collection
    {
        return WorkOrder::query()
            ->with(['vehicle.customer', 'items'])
            ->whereIn('status', self::clockableStatuses())
            ->where(function ($q) use ($technician): void {
                $q->whereHas('items', fn ($iq) => $iq->where('technician_id', $technician->id))
                    ->orWhereDoesntHave('items', fn ($iq) => $iq->whereNotNull('technician_id'));
            })
            ->orderBy('promised_at')
            ->limit(50)
            ->get();
    }

    public function start(User $technician, WorkOrder $order, ?int $itemId = null, ?string $note = null): TechnicianTimeLog
    {
        if ($this->openLogFor($technician) instanceof TechnicianTimeLog) {
            throw new DomainException('Bạn đang chấm công một lệnh khác — hãy dừng trước.');
        }

        if (! in_array($order->status, self::clockableStatuses(), true)) {
            throw new DomainException('Lệnh sửa chưa ở trạng thái làm việc (đã duyệt / đang sửa / QC).');
        }

        $item = null;
        if ($itemId !== null) {
            $item = WorkOrderItem::query()
                ->whereKey($itemId)
                ->where('work_order_id', $order->id)
                ->first();

            if (! $item instanceof WorkOrderItem) {
                throw new DomainException('Hạng mục không thuộc lệnh này.');
            }
        }

        return TechnicianTimeLog::query()->create([
            'technician_id' => $technician->id,
            'work_order_id' => $order->id,
            'work_order_item_id' => $item?->id,
            'started_at' => now(),
            'note' => filled($note) ? trim($note) : null,
        ]);
    }

    public function stop(User $technician, ?string $note = null): TechnicianTimeLog
    {
        return DB::transaction(function () use ($technician, $note): TechnicianTimeLog {
            /** @var TechnicianTimeLog|null $log */
            $log = TechnicianTimeLog::query()
                ->open()
                ->where('technician_id', $technician->id)
                ->lockForUpdate()
                ->first();

            if (! $log instanceof TechnicianTimeLog) {
                throw new DomainException('Không có phiên chấm công đang mở.');
            }

            $ended = now();
            $minutes = max(1, (int) $log->started_at->diffInMinutes($ended));

            $log->ended_at = $ended;
            $log->minutes = $minutes;
            if (filled($note)) {
                $log->note = trim(($log->note ? $log->note.' | ' : '').$note);
            }
            $log->save();

            $this->syncItemActualMinutes($log);

            return $log;
        });
    }

    public function syncItemActualMinutes(TechnicianTimeLog $log): void
    {
        if ($log->work_order_item_id === null) {
            return;
        }

        $total = (int) TechnicianTimeLog::query()
            ->where('work_order_item_id', $log->work_order_item_id)
            ->whereNotNull('minutes')
            ->sum('minutes');

        WorkOrderItem::query()->whereKey($log->work_order_item_id)->update([
            'actual_minutes' => $total > 0 ? $total : null,
        ]);
    }
}
