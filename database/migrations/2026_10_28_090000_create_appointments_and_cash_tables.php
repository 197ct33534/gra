<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('walk_in_name')->nullable();
            $table->string('walk_in_phone', 20)->nullable();
            $table->string('walk_in_plate', 20)->nullable();
            $table->timestamp('scheduled_start')->index();
            $table->timestamp('scheduled_end')->nullable();
            $table->string('lift_bay', 30)->nullable();
            $table->foreignId('service_advisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('estimated_services')->nullable();
            $table->text('customer_request')->nullable();
            $table->string('status', 20)->default('scheduled')->index();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->string('canceled_reason')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('closing_counted', 15, 2)->nullable();
            $table->decimal('expected_balance', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->restrictOnDelete();
            $table->string('direction', 10);
            $table->decimal('amount', 15, 2);
            $table->string('category', 40);
            $table->nullableMorphs('reference');
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('vat_rate')->nullable()->after('notes');
            $table->decimal('amount_before_vat', 15, 2)->nullable()->after('vat_rate');
            $table->decimal('vat_amount', 15, 2)->nullable()->after('amount_before_vat');
        });

        // Backfill VAT từ work_orders (final = net * (1 + vat/100)).
        $invoices = DB::table('invoices')
            ->join('work_orders', 'work_orders.id', '=', 'invoices.work_order_id')
            ->select('invoices.id', 'invoices.total_amount', 'work_orders.vat_rate')
            ->get();

        foreach ($invoices as $row) {
            $rate = (int) ($row->vat_rate ?? 0);
            $total = (float) $row->total_amount;
            $before = $rate > 0 ? round($total / (1 + $rate / 100), 2) : $total;
            $vat = round($total - $before, 2);

            DB::table('invoices')->where('id', $row->id)->update([
                'vat_rate' => $rate,
                'amount_before_vat' => $before,
                'vat_amount' => $vat,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'amount_before_vat', 'vat_amount']);
        });

        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('appointments');
    }
};
