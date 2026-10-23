<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mỗi trang có tour Robot riêng (bảng điều khiển, tiếp nhận nhanh, lệnh sửa chữa, hồ sơ xe):
     * lưu tour nào đã xem dạng {"dashboard": "2026-10-23 10:00:00", ...} thay cho một mốc chung.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('onboarding_tours_seen')->nullable();
        });

        DB::table('users')->whereNotNull('onboarding_tour_seen_at')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'onboarding_tours_seen' => json_encode(['dashboard' => (string) $user->onboarding_tour_seen_at]),
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('onboarding_tour_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('onboarding_tour_seen_at')->nullable();
        });

        DB::table('users')->whereNotNull('onboarding_tours_seen')->orderBy('id')->each(function (object $user): void {
            $seen = json_decode((string) $user->onboarding_tours_seen, true);

            DB::table('users')->where('id', $user->id)->update([
                'onboarding_tour_seen_at' => is_array($seen) ? ($seen['dashboard'] ?? null) : null,
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('onboarding_tours_seen');
        });
    }
};
