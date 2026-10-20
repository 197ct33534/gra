<?php

namespace App\Console\Commands;

use App\Services\MessagingService;
use Illuminate\Console\Command;

class SendCustomerMessages extends Command
{
    protected $signature = 'garage:send-messages {--limit=50 : Số tin tối đa mỗi lần chạy}';

    protected $description = 'Gửi các tin nhắn khách hàng đang chờ qua cổng tin nhắn đã cấu hình';

    public function handle(MessagingService $messaging): int
    {
        $result = $messaging->sendPending((int) $this->option('limit'));
        $this->info("Đã gửi {$result['sent']} tin, lỗi {$result['failed']} tin.");

        return self::SUCCESS;
    }
}
