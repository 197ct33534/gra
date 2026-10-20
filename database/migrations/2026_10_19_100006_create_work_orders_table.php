<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('service_advisor_id')->constrained('users')->restrictOnDelete();
            $table->integer('reception_odo');
            $table->string('fuel_level')->nullable();
            $table->text('customer_request')->nullable();
            $table->text('technical_diagnosis')->nullable();
            $table->json('checkin_checklist')->nullable();
            $table->enum('status', [
                'reception',
                'diagnosing',
                'quoted',
                'customer_approved',
                'in_progress',
                'qc',
                'completed',
                'delivered',
            ])->default('reception')->index();
            $table->decimal('total_parts_amount', 15, 2)->default(0);
            $table->decimal('total_labor_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->integer('vat_rate')->default(8);
            $table->decimal('final_amount', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
