<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;

class OnboardingTourController extends Controller
{
    /**
     * Ghi nhận tài khoản đã xem (hoặc bỏ qua) tour Robot, để lần đăng nhập sau không tự bật lại.
     */
    public function seen(): Response
    {
        $user = User::current();

        if ($user instanceof User && $user->onboarding_tour_seen_at === null) {
            $user->forceFill(['onboarding_tour_seen_at' => now()])->save();
        }

        return response()->noContent();
    }
}
