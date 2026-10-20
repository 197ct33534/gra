<?php

namespace App\Messaging;

use App\Models\CustomerMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Driver Zalo ZNS — cần access token + template đã duyệt trên Zalo OA.
 * Khi thiếu cấu hình sẽ ném lỗi rõ để admin biết chưa sẵn sàng production.
 */
class ZaloZnsGateway implements MessageGateway
{
    public function send(CustomerMessage $message): string
    {
        $token = (string) config('services.zalo.access_token', env('ZALO_OA_ACCESS_TOKEN', ''));
        $oaId = (string) config('services.zalo.oa_id', env('ZALO_OA_ID', ''));

        if ($token === '' || $oaId === '') {
            throw new MessageDeliveryException(
                'Chưa cấu hình ZALO_OA_ACCESS_TOKEN / ZALO_OA_ID. Dùng GARAGE_MESSAGING_DRIVER=log để chạy thử.'
            );
        }

        $templateId = $message->template_code
            ?: (string) config('services.zalo.default_template_id', env('ZALO_ZNS_TEMPLATE_ID', ''));

        if ($templateId === '') {
            throw new MessageDeliveryException('Thiếu mã template ZNS (template_code hoặc ZALO_ZNS_TEMPLATE_ID).');
        }

        $payload = [
            'phone' => $message->phone,
            'template_id' => $templateId,
            'template_data' => $message->template_data ?? ['content' => $message->body],
            'tracking_id' => 'gara-'.$message->id.'-'.Str::lower(Str::random(6)),
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->post('https://business.openapi.zalo.me/message/template', $payload);

        if (! $response->successful()) {
            Log::warning('zalo.zns.failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new MessageDeliveryException('Zalo ZNS lỗi HTTP '.$response->status().': '.$response->body());
        }

        $id = (string) ($response->json('data.msg_id') ?? $response->json('msg_id') ?? $payload['tracking_id']);

        return $id;
    }
}
