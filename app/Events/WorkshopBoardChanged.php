<?php

namespace App\Events;

use App\Models\WorkOrder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Báo cho mọi màn Bảng điều phối xe / Việc của tôi đang mở rằng có lệnh vừa đổi, để tự làm mới.
 *
 * Chỉ gửi mã lệnh: mỗi màn tự tải lại dữ liệu theo quyền của người đang xem, nên tín hiệu
 * không bao giờ mang tiền hay thông tin khách. Gửi ngay (không qua hàng đợi) vì cần tức thì.
 */
class WorkshopBoardChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public int $orderId;

    public function __construct(WorkOrder $order)
    {
        $this->orderId = (int) $order->getKey();
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('workshop')];
    }

    public function broadcastAs(): string
    {
        return 'board.changed';
    }

    /**
     * @return array{order_id: int}
     */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId];
    }
}
