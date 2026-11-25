<?php

namespace App\Observers;

use App\Enums\ActivityEvent;
use App\Enums\WorkOrderStatus;
use App\Events\WorkshopBoardChanged;
use App\Models\WorkOrder;
use App\Services\ActivityLogger;
use App\Support\VietnameseMoney;
use Illuminate\Support\Facades\DB;

class WorkOrderObserver
{
    /**
     * Các cột hiển thị trên Bảng điều phối xe / Việc của tôi: đổi cột nào thì báo các màn đang mở.
     */
    private const BOARD_FIELDS = ['status', 'needs_restock', 'promised_at', 'lift_bay', 'vehicle_id', 'customer_request', 'service_advisor_id', 'final_amount'];

    public function created(WorkOrder $order): void
    {
        self::signalBoard($order);
    }

    public function updated(WorkOrder $order): void
    {
        if ($order->wasChanged(self::BOARD_FIELDS)) {
            self::signalBoard($order);
        }

        if ($order->wasChanged('discount_amount')) {
            ActivityLogger::log(
                ActivityEvent::DiscountChanged,
                $order,
                'Giảm giá toàn đơn: '.VietnameseMoney::format($order->getOriginal('discount_amount')).' → '.VietnameseMoney::format($order->discount_amount),
                ['from' => (float) $order->getOriginal('discount_amount'), 'to' => (float) $order->discount_amount],
            );
        }

        if ($order->wasChanged('status')) {
            $from = WorkOrderStatus::tryFrom((string) ($order->getRawOriginal('status') ?? ''));

            ActivityLogger::log(
                ActivityEvent::StatusChanged,
                $order,
                ($from?->getLabel() ?? '—').' → '.$order->status->getLabel(),
                ['from' => $from?->value, 'to' => $order->status->value],
            );
        }
    }

    /**
     * Gửi sau khi transaction commit (màn khác tải lại phải thấy dữ liệu mới). Server realtime
     * (Reverb) tắt hoặc lỗi thì bỏ qua: màn vẫn tự làm mới theo chu kỳ, không được làm hỏng thao tác.
     */
    public static function signalBoard(WorkOrder $order): void
    {
        $id = (int) $order->getKey();

        // Một thao tác (duyệt, kéo thẻ...) lưu lệnh nhiều lần: chỉ gửi một tín hiệu cho mỗi lệnh.
        if (isset(self::$pendingSignals[$id])) {
            return;
        }

        self::$pendingSignals[$id] = true;

        DB::afterCommit(function () use ($id, $order): void {
            unset(self::$pendingSignals[$id]);
            rescue(fn () => event(new WorkshopBoardChanged($order)), report: false);
        });
    }

    /**
     * @var array<int, true>
     */
    private static array $pendingSignals = [];

    public function deleted(WorkOrder $order): void
    {
        self::signalBoard($order);

        ActivityLogger::log(
            ActivityEvent::OrderDeleted,
            $order,
            "Xóa lệnh {$order->code} (".$order->status->getLabel().', '.VietnameseMoney::format($order->final_amount).')',
            ['final_amount' => (float) $order->final_amount, 'status' => $order->status->value],
        );
    }
}
