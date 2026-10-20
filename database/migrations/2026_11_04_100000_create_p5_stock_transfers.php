<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->expandStockTransactionTypes();

        // SKU duy nhất theo chi nhánh để chuyển kho liên CN.
        Schema::table('parts', function (Blueprint $table) {
            $table->dropUnique(['sku']);
        });

        // SQLite / MySQL: đảm bảo branch_id không null trước unique kép.
        $defaultBranchId = DB::table('branches')->where('is_default', true)->value('id')
            ?? DB::table('branches')->orderBy('id')->value('id');
        if ($defaultBranchId !== null) {
            DB::table('parts')->whereNull('branch_id')->update(['branch_id' => $defaultBranchId]);
        }

        Schema::table('parts', function (Blueprint $table) {
            $table->unique(['branch_id', 'sku']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');

        Schema::table('parts', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'sku']);
            $table->unique('sku');
        });
    }

    private function expandStockTransactionTypes(): void
    {
        // MySQL: mở rộng ENUM trên DB đã migrate trước đó.
        // SQLite (tests / RefreshDatabase): create migration đã gồm đủ giá trị.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_transactions MODIFY COLUMN transaction_type ENUM('import','export_work_order','return','stocktake_adjustment','transfer_out','transfer_in') NOT NULL");
        }
    }
};
