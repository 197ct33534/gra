<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Chu kỳ bảo dưỡng riêng từng xe (VD: nhớt tổng hợp 10.000 km / 6 tháng); null = mặc định của gara.
            $table->unsignedInteger('service_interval_km')->nullable()->after('current_odo');
            $table->unsignedSmallInteger('service_interval_months')->nullable()->after('service_interval_km');
        });

        Schema::table('customers', function (Blueprint $table) {
            // Khách không muốn nhận tin nhắc bảo dưỡng (vẫn nhận biên nhận thanh toán).
            $table->boolean('reminder_opt_out')->default(false)->after('tax_code');
        });

        Schema::create('customer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->string('channel', 20);
            $table->string('phone', 20);
            $table->text('body');
            $table->string('status', 20)->default('pending')->index();
            $table->string('provider_message_id')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('scheduled_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('provider_id', 100);
            $table->string('bank')->nullable();
            $table->string('account_number', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->text('content')->nullable();
            $table->string('reference_code', 100)->nullable();
            $table->timestamp('transacted_at')->nullable();
            $table->string('status', 20)->default('unmatched')->index();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('matched_amount', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            // Ngân hàng gửi lại webhook nhiều lần: một giao dịch chỉ ghi nhận một lần.
            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('customer_messages');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('reminder_opt_out');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['service_interval_km', 'service_interval_months']);
        });
    }
};
