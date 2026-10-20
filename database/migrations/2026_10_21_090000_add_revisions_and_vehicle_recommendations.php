<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // Đợt báo giá cao nhất đã chốt với khách (đã giữ kho). Đợt lớn hơn = phát sinh chờ duyệt.
            $table->unsignedInteger('approved_revision')->default(0)->after('needs_restock');
        });

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->text('finding_note')->nullable()->after('revision_no');
            $table->string('decline_reason')->nullable()->after('is_approved');
            $table->timestamp('declined_at')->nullable()->after('decline_reason');
        });

        Schema::create('vehicle_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('work_order_item_id')->nullable()->unique()->constrained('work_order_items')->nullOnDelete();
            $table->enum('item_type', ['service', 'part']);
            $table->unsignedBigInteger('item_id');
            $table->string('item_name');
            $table->unsignedInteger('revision_no')->default(1);
            $table->string('decline_reason')->nullable();
            $table->integer('odo')->nullable();
            $table->timestamp('declined_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'resolved_at']);
        });

        // Lệnh đã duyệt trước khi có khái niệm phát sinh: coi mọi hạng mục hiện có là đã chốt.
        DB::table('work_orders')
            ->whereIn('status', ['customer_approved', 'in_progress', 'qc', 'completed', 'delivered'])
            ->update([
                'approved_revision' => DB::raw('coalesce((select max(revision_no) from work_order_items where work_order_items.work_order_id = work_orders.id), 1)'),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_recommendations');

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn(['finding_note', 'decline_reason', 'declined_at']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('approved_revision');
        });
    }
};
