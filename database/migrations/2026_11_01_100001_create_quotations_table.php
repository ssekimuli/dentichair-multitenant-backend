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
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number')->unique();
            $table->enum('customer_type', ['patient', 'lab_client']);
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignId('lab_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('dentist_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('lab_order_id')->nullable()->constrained('laboratory_orders')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->enum('source_type', ['manual', 'treatment_plan', 'lab_order', 'mixed'])->default('manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('quotation_date');
            $table->date('valid_until');
            $table->string('currency', 10)->default('USD');
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->string('discount_type', 20)->nullable()->default('none');
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->decimal('tax_rate', 5, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('total', 12, 2)->default(0.00);
            $table->enum('status', ['draft', 'sent', 'approved', 'rejected', 'expired', 'converted', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->text('terms_conditions')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('original_quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->unsignedInteger('revision_number')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            // Indexes
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'customer_type']);
            $table->index(['customer_type', 'patient_id']);
            $table->index(['customer_type', 'lab_client_id']);
            $table->index('quotation_date');
            $table->index('valid_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};

