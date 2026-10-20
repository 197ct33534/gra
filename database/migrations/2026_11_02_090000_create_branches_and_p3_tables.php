<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('hotline', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'CN-HCM',
            'name' => 'Chi nhánh chính',
            'address' => null,
            'hotline' => null,
            'is_active' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('commission_rate')->constrained('branches')->nullOnDelete();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('code')->constrained('branches')->nullOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('code')->constrained('branches')->nullOnDelete();
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
        });

        Schema::table('customer_messages', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->after('invoice_id')->constrained('work_orders')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points_redeemed')->default(0)->after('paid_amount');
            $table->decimal('loyalty_discount_amount', 15, 2)->default(0)->after('loyalty_points_redeemed');
        });

        // Gán dữ liệu cũ về chi nhánh mặc định (scaffold — chưa ép filter).
        DB::table('work_orders')->whereNull('branch_id')->update(['branch_id' => $branchId]);
        DB::table('users')->whereNull('branch_id')->update(['branch_id' => $branchId]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points_redeemed', 'loyalty_discount_amount']);
        });

        Schema::table('customer_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_id');
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::dropIfExists('branches');
    }
};
