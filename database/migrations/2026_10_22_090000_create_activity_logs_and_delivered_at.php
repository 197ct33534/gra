<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40)->index();
            $table->nullableMorphs('subject');
            $table->string('subject_label')->nullable();
            $table->text('description');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            // Ngày bàn giao: mốc tính lãi gộp theo tháng.
            $table->timestamp('delivered_at')->nullable()->after('stock_exported_at')->index();
        });

        DB::table('work_orders')
            ->where('status', 'delivered')
            ->update(['delivered_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['delivered_at']);
            $table->dropColumn('delivered_at');
        });

        Schema::dropIfExists('activity_logs');
    }
};
