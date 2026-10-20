<?php

namespace App\Messaging;

use App\Models\CustomerMessage;

/**
 * Driver đã chọn nhưng chưa có tích hợp: tin để trạng thái lỗi, không âm thầm "gửi" giả.
 */
class UnconfiguredMessageGateway implements MessageGateway
{
    public function __construct(private readonly string $driver) {}

    public function send(CustomerMessage $message): string
    {
        throw new MessageDeliveryException("Chưa tích hợp nhà cung cấp tin nhắn \"{$this->driver}\". Đặt GARAGE_MESSAGING_DRIVER=log để chạy thử.");
    }
}
