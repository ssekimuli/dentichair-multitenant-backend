<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('claim_items');
        Schema::dropIfExists('insurance_claim_documents');
        Schema::dropIfExists('insurance_claims');
        Schema::dropIfExists('patient_insurance_policies');
        Schema::dropIfExists('insurance_policies');
        Schema::dropIfExists('insurance_package_treatments');
        Schema::dropIfExists('insurance_packages');
        Schema::dropIfExists('insurance_companies');

        Schema::create('insurance_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        Schema::create('insurance_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_company_id')->constrained('insurance_companies')->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->decimal('default_coverage_percentage', 5, 2)->default(100.00);
            $table->decimal('default_annual_limit', 15, 2)->nullable();
            $table->boolean('requires_pre_authorization')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['insurance_company_id', 'code']);
            $table->index('status');
        });

        Schema::create('insurance_package_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_package_id')->constrained('insurance_packages')->cascadeOnDelete();
            $table->foreignId('procedure_id')->constrained('treatments')->restrictOnDelete();
            $table->enum('coverage_type', ['percentage', 'fixed_amount'])->default('percentage');
            $table->decimal('coverage_value', 15, 2)->default(100.00);
            $table->decimal('maximum_covered_amount', 15, 2)->nullable();
            $table->integer('frequency_limit')->nullable();
            $table->integer('waiting_period_days')->default(0);
            $table->boolean('requires_pre_authorization')->default(false);
            $table->text('exclusion_notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['insurance_package_id', 'procedure_id'], 'pkg_procedure_unique');
        });

        Schema::create('insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_package_id')->constrained('insurance_packages')->restrictOnDelete();
            $table->string('policy_number')->unique();
            $table->enum('policy_type', ['individual', 'family', 'corporate'])->default('individual');
            $table->string('employer_or_group_name')->nullable();
            $table->date('coverage_start_date');
            $table->date('coverage_end_date');
            $table->decimal('total_coverage_limit', 15, 2)->nullable();
            $table->decimal('used_amount', 15, 2)->default(0.00);
            $table->decimal('remaining_balance', 15, 2)->nullable();
            $table->enum('status', ['pending', 'active', 'suspended', 'expired', 'cancelled'])->default('pending');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'coverage_end_date']);
        });

        Schema::create('patient_insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('insurance_policy_id')->constrained('insurance_policies')->restrictOnDelete();
            $table->string('membership_number')->nullable();
            $table->foreignId('principal_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('principal_member_name')->nullable();
            $table->enum('relationship', ['principal', 'spouse', 'child', 'other'])->default('principal');
            $table->date('coverage_start_date')->nullable();
            $table->date('coverage_end_date')->nullable();
            $table->decimal('individual_coverage_limit', 15, 2)->nullable();
            $table->decimal('used_amount', 15, 2)->default(0.00);
            $table->decimal('remaining_balance', 15, 2)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->string('insurance_card')->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->enum('status', ['active', 'suspended', 'expired', 'cancelled'])->default('active');
            $table->timestamps();
            $table->index(['patient_id', 'is_primary', 'status']);
        });
        
        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number')->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('insurance_policy_id')->constrained('insurance_policies')->restrictOnDelete();
            $table->foreignId('insurance_company_id')->constrained('insurance_companies')->restrictOnDelete();
            $table->decimal('submitted_amount', 15, 2)->default(0);
            $table->decimal('approved_amount', 15, 2)->default(0);
            $table->decimal('rejected_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            $table->date('submission_date')->nullable();
            $table->date('approval_date')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->enum('status', [
                'Draft', 'Submitted', 'Under Review', 'Approved', 
                'Partially Approved', 'Rejected', 'Paid', 'Cancelled'
            ])->default('Draft');
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'insurance_company_id']);
        });

        Schema::create('claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_claim_id')->constrained('insurance_claims')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_treatments')->restrictOnDelete();
            $table->foreignId('procedure_id')->constrained('treatments')->restrictOnDelete();
            $table->decimal('claimed_amount', 15, 2)->default(0);
            $table->decimal('approved_amount', 15, 2)->default(0);
            $table->decimal('rejected_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->text('rejection_reason')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Partially Approved', 'Rejected'])->default('Pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_items');
        Schema::dropIfExists('insurance_claims');
        Schema::dropIfExists('patient_insurance_policies');
        Schema::dropIfExists('insurance_policies');
        Schema::dropIfExists('insurance_package_treatments');
        Schema::dropIfExists('insurance_packages');
        Schema::dropIfExists('insurance_companies');
    }
};
