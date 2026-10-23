<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Robot dẫn đường tự chạy lần đầu đăng nhập; đã xem/bỏ qua thì không tự hiện lại (nút Trợ giúp vẫn mở lại được).
            $table->timestamp('onboarding_tour_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('onboarding_tour_seen_at');
        });
    }
};
