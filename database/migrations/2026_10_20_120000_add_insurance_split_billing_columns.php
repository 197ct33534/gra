<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            // Hạng mục khách tự trả trên lệnh bảo hiểm (giám định không duyệt hoặc khách làm thêm).
            $table->boolean('is_outside_insurance')->default(false)->after('is_approved');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('payer_type', ['customer', 'insurance'])->default('customer')->after('work_order_id')->index();
            $table->string('payer_name')->nullable()->after('payer_type');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['payer_type']);
            $table->dropColumn(['payer_type', 'payer_name']);
        });

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn('is_outside_insurance');
        });
    }
};
