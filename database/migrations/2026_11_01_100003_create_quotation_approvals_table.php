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
        Schema::create('quotation_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->enum('approval_method', ['clinic_staff', 'patient_link', 'lab_client_link']);
            $table->enum('decision', ['approved', 'rejected']);
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('approved_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index('quotation_id');
            $table->index('decision');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_approvals');
    }
};

