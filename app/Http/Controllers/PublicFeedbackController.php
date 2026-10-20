<?php

namespace App\Http\Controllers;

use App\Services\CustomerPortalService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicFeedbackController extends Controller
{
    public function show(string $token, CustomerPortalService $portal): View
    {
        try {
            $order = $portal->findByFeedbackToken($token);
        } catch (DomainException $e) {
            abort(404, $e->getMessage());
        }

        return view('public.feedback', [
            'order' => $order,
            'token' => $token,
            'garage' => config('garage'),
        ]);
    }

    public function store(string $token, Request $request, CustomerPortalService $portal): RedirectResponse
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $portal->submitFeedback($token, (int) $data['score'], $data['comment'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('public.feedback.show', $token)
            ->with('success', 'Cảm ơn quý khách đã đánh giá!');
    }
}
