<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->unsignedInteger('actual_minutes')->nullable()->after('technician_payout');
        });

        Schema::create('technician_time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('work_order_item_id')->nullable()->constrained('work_order_items')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['technician_id', 'ended_at']);
            $table->index(['work_order_id', 'ended_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('technician_time_logs');

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn('actual_minutes');
        });
    }
};
