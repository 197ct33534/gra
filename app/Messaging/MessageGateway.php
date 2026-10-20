<?php

namespace App\Messaging;

use App\Models\CustomerMessage;

/**
 * Cổng gửi tin cho khách (Zalo ZNS, SMS brandname...). Mỗi nhà cung cấp là một driver.
 */
interface MessageGateway
{
    /**
     * Trả về mã tin của nhà cung cấp; ném MessageDeliveryException nếu gửi lỗi.
     */
    public function send(CustomerMessage $message): string;
}
