<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('compatibility');
            $table->unsignedInteger('warranty_km')->nullable()->after('warranty_months');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('standard_time_minutes');
            $table->unsignedInteger('warranty_km')->nullable()->after('warranty_months');
        });

        Schema::create('warranty_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->restrictOnDelete();
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id');
            $table->string('item_name');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('start_odo')->nullable();
            $table->unsignedInteger('end_odo')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('terms')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_card_id')->constrained('warranty_cards')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->text('symptom');
            $table->text('resolution')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('warranty_card_id')
                ->nullable()
                ->after('order_type')
                ->constrained('warranty_cards')
                ->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('einvoice_status', 20)->nullable()->index()->after('vat_amount');
            $table->string('einvoice_number', 50)->nullable()->after('einvoice_status');
            $table->string('einvoice_series', 20)->nullable()->after('einvoice_number');
            $table->string('einvoice_template', 20)->nullable()->after('einvoice_series');
            $table->string('einvoice_provider', 30)->nullable()->after('einvoice_template');
            $table->string('einvoice_provider_id', 100)->nullable()->after('einvoice_provider');
            $table->string('einvoice_tax_authority_code', 50)->nullable()->after('einvoice_provider_id');
            $table->string('einvoice_lookup_url', 500)->nullable()->after('einvoice_tax_authority_code');
            $table->timestamp('einvoice_issued_at')->nullable()->after('einvoice_lookup_url');
            $table->timestamp('einvoice_canceled_at')->nullable()->after('einvoice_issued_at');
            $table->text('einvoice_error')->nullable()->after('einvoice_canceled_at');
            $table->json('einvoice_payload')->nullable()->after('einvoice_error');

            $table->unique(['einvoice_provider', 'einvoice_provider_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('messaging_opt_in')->default(false)->after('reminder_opt_out');
            $table->timestamp('messaging_opt_in_at')->nullable()->after('messaging_opt_in');
            $table->string('messaging_opt_in_source', 40)->nullable()->after('messaging_opt_in_at');
        });

        Schema::table('customer_messages', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('error');
            $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            $table->string('template_code', 50)->nullable()->after('last_attempt_at');
            $table->json('template_data')->nullable()->after('template_code');
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('channel', 20);
            $table->string('template_code', 50);
            $table->text('body_fallback')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');

        Schema::table('customer_messages', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'last_attempt_at', 'template_code', 'template_data']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['messaging_opt_in', 'messaging_opt_in_at', 'messaging_opt_in_source']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['einvoice_provider', 'einvoice_provider_id']);
            $table->dropColumn([
                'einvoice_status',
                'einvoice_number',
                'einvoice_series',
                'einvoice_template',
                'einvoice_provider',
                'einvoice_provider_id',
                'einvoice_tax_authority_code',
                'einvoice_lookup_url',
                'einvoice_issued_at',
                'einvoice_canceled_at',
                'einvoice_error',
                'einvoice_payload',
            ]);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_card_id');
        });

        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('warranty_cards');

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['warranty_months', 'warranty_km']);
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn(['warranty_months', 'warranty_km']);
        });
    }
};
