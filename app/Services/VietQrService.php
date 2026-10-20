<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

class VietQrService
{
    public function generateQrUrl(int|float $amount, string $description): string
    {
        $bankId = rawurlencode((string) config('garage.bank_id'));
        $accountNo = rawurlencode((string) config('garage.account_no'));
        $query = http_build_query([
            'amount' => $this->amount($amount),
            'addInfo' => $description,
            'accountName' => (string) config('garage.account_name'),
        ], '', '&', PHP_QUERY_RFC3986);

        return "https://img.vietqr.io/image/{$bankId}-{$accountNo}-compact2.png?{$query}";
    }

    public function generateBase64Qr(int|float $amount, string $description): string
    {
        $url = $this->generateQrUrl($amount, $description);

        try {
            $response = Http::connectTimeout(2)->timeout(4)->get($url);

            if ($response->successful() && $response->body() !== '') {
                $contentType = strtolower((string) $response->header('Content-Type'));
                $mime = str_contains($contentType, 'svg') ? 'image/svg+xml' : 'image/png';

                return 'data:'.$mime.';base64,'.base64_encode($response->body());
            }
        } catch (Throwable) {
            // The local QR below keeps PDF rendering working when VietQR is unreachable.
        }

        $svg = QrCode::format('svg')->size(280)->margin(1)->errorCorrection('M')->generate($url);

        return 'data:image/svg+xml;base64,'.base64_encode((string) $svg);
    }

    private function amount(int|float $amount): int
    {
        return max(0, (int) round($amount));
    }
}
