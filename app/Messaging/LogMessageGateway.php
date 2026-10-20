<?php

namespace App\Messaging;

use App\Models\CustomerMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Driver giả lập: không gửi thật, chỉ ghi vào log để chạy thử trước khi có tài khoản Zalo/SMS.
 */
class LogMessageGateway implements MessageGateway
{
    public function send(CustomerMessage $message): string
    {
        Log::info('[Tin nhắn giả lập] '.$message->channel.' → '.$message->phone.': '.$message->body);

        return 'log-'.Str::lower(Str::random(12));
    }
}
