<?php

namespace App\Http\Controllers;

use App\Services\QuoteApprovalService;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicQuoteController extends Controller
{
    public function show(string $token, QuoteApprovalService $quotes): View
    {
        try {
            $order = $quotes->findByToken($token);
        } catch (DomainException $e) {
            abort(404, $e->getMessage());
        }

        $order->loadMissing(['items', 'vehicle.customer']);

        return view('public.quote', [
            'order' => $order,
            'token' => $token,
            'garage' => config('garage'),
            'money' => fn (mixed $v): string => VietnameseMoney::format($v),
        ]);
    }

    public function approve(string $token, QuoteApprovalService $quotes): RedirectResponse
    {
        try {
            $order = $quotes->approveByCustomer($token);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('public.quote.show', $token)
            ->with('success', 'Đã duyệt báo giá '.$order->code.'. Gara sẽ tiến hành sửa chữa.');
    }

    public function reject(string $token, Request $request, QuoteApprovalService $quotes): RedirectResponse
    {
        try {
            $quotes->rejectByCustomer($token, $request->input('reason'));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('public.quote.show', $token)
            ->with('success', 'Đã từ chối báo giá. Cảm ơn quý khách đã phản hồi.');
    }
}
