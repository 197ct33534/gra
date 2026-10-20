<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookService
{
    public function dispatchDelivered(WorkOrder $order): bool
    {
        $url = (string) config('garage.webhooks.delivered_url', '');
        if ($url === '') {
            return false;
        }

        $order->loadMissing(['vehicle.customer', 'branch']);

        $payload = [
            'event' => 'work_order.delivered',
            'occurred_at' => now()->toIso8601String(),
            'work_order' => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status->value,
                'final_amount' => (float) $order->final_amount,
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'branch' => $order->branch?->code,
                'vehicle' => [
                    'license_plate' => $order->vehicle?->license_plate,
                    'brand' => $order->vehicle?->brand,
                    'model' => $order->vehicle?->model,
                ],
                'customer' => [
                    'name' => $order->vehicle?->customer?->name,
                    'phone' => $order->vehicle?->customer?->phone,
                ],
            ],
        ];

        $secret = (string) config('garage.webhooks.secret', '');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'GarageX-Webhook/1.0',
            'X-Garage-Event' => 'work_order.delivered',
        ];

        if ($secret !== '') {
            $headers['X-Garage-Signature'] = 'sha256='.hash_hmac('sha256', (string) $body, $secret);
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders($headers)
                ->withBody((string) $body, 'application/json')
                ->post($url);

            if (! $response->successful()) {
                Log::warning('Webhook delivered failed', [
                    'code' => $order->code,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 200),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Webhook delivered exception', [
                'code' => $order->code,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
