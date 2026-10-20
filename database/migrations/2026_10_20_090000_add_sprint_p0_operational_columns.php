<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->enum('item_source', ['stock', 'direct_purchase', 'subcontract', 'customer_supplied'])
                ->default('stock')
                ->after('item_id');
            $table->decimal('cost_price', 15, 2)->default(0)->after('unit_price');
            $table->string('supplier_info')->nullable()->after('cost_price');
            $table->string('warranty_policy')->nullable()->after('supplier_info');
            $table->integer('revision_no')->default(1)->after('warranty_policy');
            $table->boolean('is_approved')->default(true)->after('revision_no');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->enum('order_type', ['standard', 'insurance', 'warranty_redo'])
                ->default('standard')
                ->after('vehicle_id');
            $table->string('insurance_company')->nullable()->after('order_type');
            $table->decimal('insurance_deductible', 15, 2)->default(0)->after('insurance_company');
            $table->timestamp('promised_at')->nullable()->after('checkin_checklist');
            $table->string('lift_bay', 50)->nullable()->after('promised_at');
            $table->boolean('needs_restock')->default(false)->after('status');
            $table->timestamp('stock_exported_at')->nullable()->after('needs_restock');
            $table->decimal('gross_profit', 15, 2)->nullable()->after('final_amount');
        });

        // Lệnh duyệt theo logic cũ đã trừ kho ngay lúc duyệt: đánh dấu để lúc bàn giao không trừ lần hai.
        DB::table('work_orders')
            ->whereIn('status', ['customer_approved', 'in_progress', 'qc', 'completed', 'delivered'])
            ->update(['stock_exported_at' => DB::raw('updated_at')]);

        Schema::table('parts', function (Blueprint $table) {
            $table->integer('reserved_quantity')->default(0)->after('stock_quantity');
        });

        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->string('note')->nullable()->after('reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->dropColumn('note');
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('reserved_quantity');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'order_type',
                'insurance_company',
                'insurance_deductible',
                'promised_at',
                'lift_bay',
                'needs_restock',
                'stock_exported_at',
                'gross_profit',
            ]);
        });

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'item_source',
                'cost_price',
                'supplier_info',
                'warranty_policy',
                'revision_no',
                'is_approved',
            ]);
        });
    }
};
