<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('insurance_claim_no', 80)->nullable()->after('insurance_deductible');
            $table->string('insurance_adjuster')->nullable()->after('insurance_claim_no');
            $table->date('insurance_expected_pay_at')->nullable()->after('insurance_adjuster');
            $table->string('insurance_claim_status', 30)->nullable()->after('insurance_expected_pay_at');
            $table->string('quote_approval_token', 64)->nullable()->unique()->after('approved_revision');
            $table->timestamp('quote_approved_at')->nullable()->after('quote_approval_token');
            $table->string('quote_approved_via', 20)->nullable()->after('quote_approved_at');
            $table->timestamp('quote_rejected_at')->nullable()->after('quote_approved_via');
            $table->string('quote_reject_reason')->nullable()->after('quote_rejected_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->nullable()->after('tax_code');
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('credit_limit');
        });

        Schema::create('fleet_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('reason');
            $table->boolean('restock')->default(false);
            $table->string('status', 20)->default('issued');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('fleet_contracts');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'payment_terms_days']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_claim_no',
                'insurance_adjuster',
                'insurance_expected_pay_at',
                'insurance_claim_status',
                'quote_approval_token',
                'quote_approved_at',
                'quote_approved_via',
                'quote_rejected_at',
                'quote_reject_reason',
            ]);
        });
    }
};
