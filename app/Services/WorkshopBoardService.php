<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bảng điều phối xưởng: gom lệnh theo cột và chuyển cột theo đúng quy tắc giữ kho.
 */
class WorkshopBoardService
{
    public const DIAGNOSING = 'diagnosing';

    public const QUOTED = 'quoted';

    public const IN_PROGRESS = 'in_progress';

    public const WAITING_PARTS = 'waiting_parts';

    public const QC = 'qc';

    public function __construct(private readonly WorkOrderService $workOrders) {}

    /**
     * @return array<string, string>
     */
    public static function columns(): array
    {
        return [
            self::DIAGNOSING => 'Chờ chẩn đoán',
            self::QUOTED => 'Chờ khách duyệt',
            self::IN_PROGRESS => 'Đang sửa chữa',
            self::WAITING_PARTS => 'Chờ phụ tùng / Cần mua ngoài',
            self::QC => 'KCS & Chờ giao xe',
        ];
    }

    public static function columnFor(WorkOrder $order): ?string
    {
        return match ($order->status) {
            WorkOrderStatus::Reception, WorkOrderStatus::Diagnosing => self::DIAGNOSING,
            WorkOrderStatus::Quoted => self::QUOTED,
            WorkOrderStatus::CustomerApproved, WorkOrderStatus::InProgress => $order->needs_restock ? self::WAITING_PARTS : self::IN_PROGRESS,
            WorkOrderStatus::Qc, WorkOrderStatus::Completed => self::QC,
            WorkOrderStatus::Delivered => null,
        };
    }

    /**
     * @return array<string, Collection<int, WorkOrder>>
     */
    public function board(): array
    {
        $orders = WorkOrder::query()
            ->where('status', '!=', WorkOrderStatus::Delivered)
            ->with(['vehicle.customer', 'serviceAdvisor'])
            ->withCount(['items as approved_items_count' => fn ($query) => $query->where('is_approved', true)])
            ->orderByRaw('promised_at is null')
            ->orderBy('promised_at')
            ->orderBy('created_at')
            ->get();

        $board = [];

        foreach (array_keys(self::columns()) as $column) {
            $board[$column] = $orders
                ->filter(fn (WorkOrder $order): bool => self::columnFor($order) === $column)
                ->values();
        }

        return $board;
    }

    /**
     * Chuyển lệnh sang cột khác. Kéo lệnh chưa duyệt sang cột sửa chữa nghĩa là khách đã đồng ý: duyệt và giữ kho.
     * Kéo lệnh đã duyệt về cột chẩn đoán/báo giá: hủy duyệt, nhả kho (không cho phép khi đã lập hóa đơn).
     *
     * @return list<string> Cảnh báo thiếu hàng nếu vừa duyệt.
     */
    public function move(WorkOrder $workOrder, string $column, int $userId): array
    {
        if (! array_key_exists($column, self::columns())) {
            throw new DomainException('Cột không hợp lệ.');
        }

        $warnings = DB::transaction(function () use ($workOrder, $column, $userId): array {
            $locked = WorkOrder::query()->whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === WorkOrderStatus::Delivered) {
                throw new DomainException('Xe đã bàn giao, không chuyển trạng thái được.');
            }

            if (self::columnFor($locked) === $column) {
                return [];
            }

            $this->assertCanMove(User::query()->find($userId), $locked, $column);

            $warnings = [];
            $justApproved = false;
            $preApproval = in_array($column, [self::DIAGNOSING, self::QUOTED], true);

            if ($preApproval && $locked->status->stockIsCommitted()) {
                if ($locked->invoices()->exists()) {
                    throw new DomainException('Lệnh đã lập hóa đơn, không đưa về bước báo giá được.');
                }

                $this->workOrders->cancelApproval($locked, $userId);
                $locked->refresh();
            }

            if (! $preApproval && ! $locked->status->stockIsCommitted()) {
                $warnings = $this->workOrders->approveWorkOrder($locked, $userId);
                $justApproved = true;
                $locked->refresh();
            }

            match ($column) {
                self::DIAGNOSING => $locked->status = WorkOrderStatus::Diagnosing,
                self::QUOTED => $locked->status = WorkOrderStatus::Quoted,
                self::IN_PROGRESS => $this->toInProgress($locked, $justApproved && $warnings !== []),
                self::WAITING_PARTS => $this->toWaitingParts($locked),
                self::QC => $this->toQc($locked),
            };

            $locked->save();

            return $warnings;
        });

        $workOrder->refresh();

        return $warnings;
    }

    /**
     * Cột thợ được tự chuyển: chỉ trong khâu sửa chữa. Duyệt / hủy duyệt là việc của cố vấn với khách.
     *
     * @return list<string>
     */
    public static function workshopColumns(): array
    {
        return [self::IN_PROGRESS, self::WAITING_PARTS, self::QC];
    }

    public static function canMoveTo(?User $user, WorkOrder $order, string $column): bool
    {
        if (! $user instanceof User || ! $user->isTechnician()) {
            return true;
        }

        return in_array($column, self::workshopColumns(), true)
            && in_array(self::columnFor($order), self::workshopColumns(), true);
    }

    private function assertCanMove(?User $user, WorkOrder $order, string $column): void
    {
        if (! self::canMoveTo($user, $order, $column)) {
            throw new DomainException('Kỹ thuật viên chỉ chuyển xe giữa Đang sửa, Chờ phụ tùng và KCS. Duyệt báo giá do cố vấn dịch vụ làm.');
        }
    }

    private function toInProgress(WorkOrder $order, bool $stillShort): void
    {
        $order->status = WorkOrderStatus::InProgress;
        // Kéo về "Đang sửa" = hàng đã về; riêng lần vừa duyệt mà kho thiếu thì vẫn giữ cờ chờ đồ.
        $order->needs_restock = $stillShort;
    }

    private function toWaitingParts(WorkOrder $order): void
    {
        if (in_array($order->status, [WorkOrderStatus::Qc, WorkOrderStatus::Completed], true)) {
            $order->status = WorkOrderStatus::InProgress;
        }

        $order->needs_restock = true;
    }

    private function toQc(WorkOrder $order): void
    {
        if ($order->status !== WorkOrderStatus::Completed) {
            $order->status = WorkOrderStatus::Qc;
        }

        $order->needs_restock = false;
    }
}
