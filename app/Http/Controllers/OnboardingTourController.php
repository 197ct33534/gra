<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\OnboardingTours;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class OnboardingTourController extends Controller
{
    /**
     * Ghi nhận tài khoản đã xem (hoặc bỏ qua) tour Robot của một trang, để lần sau không tự bật lại.
     */
    public function seen(Request $request): Response
    {
        $data = $request->validate([
            'tour' => ['required', 'string', Rule::in(OnboardingTours::KEYS)],
        ]);

        User::current()?->markTourSeen($data['tour']);

        return response()->noContent();
    }
}
