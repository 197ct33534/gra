<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // % tiền công thợ được hưởng; null = dùng mức mặc định của gara.
            $table->decimal('commission_rate', 5, 2)->nullable()->after('role');
        });

        Schema::table('services', function (Blueprint $table) {
            // Khoán cố định cho mỗi lần làm (VD: thay nhớt 30.000 đ); null = tính theo % tiền công.
            $table->decimal('technician_payout', 15, 2)->nullable()->after('labor_cost');
        });

        Schema::table('work_order_items', function (Blueprint $table) {
            // Tiền khoán chốt lúc bàn giao xe, để đổi mức khoán sau này không làm sai lương tháng cũ.
            $table->decimal('technician_payout', 15, 2)->nullable()->after('technician_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn('technician_payout');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('technician_payout');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }
};
