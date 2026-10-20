<?php

namespace App\Http\Controllers;

use App\Services\BankReconciliationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook SePay báo tiền về tài khoản gara.
 */
class BankWebhookController extends Controller
{
    public function sepay(Request $request, BankReconciliationService $reconciliation): JsonResponse
    {
        $key = (string) config('garage.bank_webhook.sepay_api_key');

        if ($key === '') {
            return response()->json(['success' => false, 'message' => 'Webhook chưa cấu hình khóa.'], 503);
        }

        if (! hash_equals('Apikey '.$key, (string) $request->header('Authorization'))) {
            return response()->json(['success' => false, 'message' => 'Sai khóa xác thực.'], 401);
        }

        try {
            $transaction = $reconciliation->ingestSepay($request->json()->all());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'status' => $transaction->status->value]);
    }
}
