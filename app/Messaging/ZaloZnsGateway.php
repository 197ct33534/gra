<?php

namespace App\Messaging;

use App\Models\CustomerMessage;
use Illuminate\Http\Client\ConnectionException;
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
        // Đọc qua config (không gọi env() ở đây) để vẫn chạy đúng khi `php artisan config:cache`.
        $token = (string) config('services.zalo.access_token', '');
        $oaId = (string) config('services.zalo.oa_id', '');

        if ($token === '' || $oaId === '') {
            throw new MessageDeliveryException(
                'Chưa cấu hình ZALO_OA_ACCESS_TOKEN / ZALO_OA_ID. Dùng GARAGE_MESSAGING_DRIVER=log để chạy thử.'
            );
        }

        $templateId = $message->template_code ?: (string) config('services.zalo.default_template_id', '');

        if ($templateId === '') {
            throw new MessageDeliveryException('Thiếu mã template ZNS (template_code hoặc ZALO_ZNS_TEMPLATE_ID).');
        }

        $payload = [
            'phone' => self::internationalPhone($message->phone),
            'template_id' => $templateId,
            'template_data' => $message->template_data ?? ['content' => $message->body],
            'tracking_id' => 'gara-'.$message->id.'-'.Str::lower(Str::random(6)),
        ];

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->post((string) config('services.zalo.endpoint', 'https://business.openapi.zalo.me/message/template'), $payload);
        } catch (ConnectionException $exception) {
            // Mất mạng / quá thời gian: báo lỗi cho tin này, để lệnh gửi tiếp các tin khác.
            throw new MessageDeliveryException('Không kết nối được Zalo ZNS: '.$exception->getMessage());
        }

        if (! $response->successful()) {
            Log::warning('zalo.zns.failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new MessageDeliveryException('Zalo ZNS lỗi HTTP '.$response->status().': '.mb_substr($response->body(), 0, 200));
        }

        // Zalo trả HTTP 200 kể cả khi lỗi (token hết hạn, sai template...): kiểm tra mã "error".
        $error = (int) ($response->json('error') ?? 0);

        if ($error !== 0) {
            Log::warning('zalo.zns.rejected', ['error' => $error, 'body' => $response->body()]);
            throw new MessageDeliveryException('Zalo ZNS từ chối (mã '.$error.'): '.(string) ($response->json('message') ?? ''));
        }

        return (string) ($response->json('data.msg_id') ?? $response->json('msg_id') ?? $payload['tracking_id']);
    }

    /**
     * ZNS nhận số dạng 84xxxxxxxxx; gara lưu dạng 0xxxxxxxxx.
     */
    public static function internationalPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return str_starts_with($digits, '0') ? '84'.substr($digits, 1) : $digits;
    }
}
