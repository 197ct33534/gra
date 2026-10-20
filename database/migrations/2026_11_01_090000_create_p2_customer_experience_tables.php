<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('status_token', 64)->nullable()->unique()->after('quote_reject_reason');
            $table->string('feedback_token', 64)->nullable()->unique()->after('status_token');
            $table->unsignedTinyInteger('feedback_score')->nullable()->after('feedback_token');
            $table->text('feedback_comment')->nullable()->after('feedback_score');
            $table->timestamp('feedback_at')->nullable()->after('feedback_comment');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('registration_expires_at')->nullable()->after('current_odo');
            $table->date('insurance_expires_at')->nullable()->after('registration_expires_at');
            $table->date('inspection_expires_at')->nullable()->after('insurance_expires_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points')->default(0)->after('payment_terms_days');
            $table->string('loyalty_tier', 20)->default('member')->after('loyalty_points');
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->integer('points');
            $table->unsignedInteger('balance_after');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points', 'loyalty_tier']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'registration_expires_at',
                'insurance_expires_at',
                'inspection_expires_at',
            ]);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'status_token',
                'feedback_token',
                'feedback_score',
                'feedback_comment',
                'feedback_at',
            ]);
        });
    }
};
