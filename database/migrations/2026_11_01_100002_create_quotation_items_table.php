<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->foreignId('treatment_plan_item_id')->nullable()->constrained('treatment_plans')->nullOnDelete();
            $table->foreignId('lab_order_item_id')->nullable()->constrained('laboratory_orders')->nullOnDelete();
            $table->string('item_type', 50)->default('procedure');
            $table->string('description');
            $table->string('tooth_number', 50)->nullable();
            $table->string('material', 100)->nullable();
            $table->string('shade', 50)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0.00);
            $table->string('discount_type', 20)->nullable()->default('none');
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->decimal('tax_rate', 5, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('total', 12, 2)->default(0.00);
            $table->date('expected_delivery_date')->nullable();
            $table->text('special_instructions')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('quotation_id');
            $table->index('treatment_plan_item_id');
            $table->index('lab_order_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};

