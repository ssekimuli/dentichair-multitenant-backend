<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('patient_responsibility', 15, 2)->default(0)->after('total_amount');
            $table->decimal('insurance_responsibility', 15, 2)->default(0)->after('patient_responsibility');
            $table->decimal('patient_paid_amount', 15, 2)->default(0)->after('insurance_responsibility');
            $table->decimal('insurance_paid_amount', 15, 2)->default(0)->after('patient_paid_amount');
            $table->decimal('patient_balance', 15, 2)->default(0)->after('insurance_paid_amount');
            $table->decimal('insurance_balance', 15, 2)->default(0)->after('patient_balance');
        });

        Schema::table('invoice_treatments', function (Blueprint $table) {
            $table->decimal('covered_amount', 15, 2)->default(0)->after('total_amount');
            $table->decimal('insurance_amount', 15, 2)->default(0)->after('covered_amount');
            $table->decimal('patient_amount', 15, 2)->default(0)->after('insurance_amount');
            $table->decimal('excluded_amount', 15, 2)->default(0)->after('patient_amount');
            $table->decimal('coverage_percentage', 5, 2)->nullable()->after('excluded_amount');
            $table->json('coverage_rule_snapshot')->nullable()->after('coverage_percentage');
            $table->string('pre_authorization_number')->nullable()->after('coverage_rule_snapshot');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('insurance_claim_id')->nullable()->constrained('insurance_claims')->nullOnDelete()->after('invoice_id');
            $table->string('payer_type')->default('patient')->after('insurance_claim_id');
            $table->unsignedBigInteger('payer_id')->nullable()->after('payer_type');
            $table->index(['payer_type', 'payer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign(['insurance_claim_id']);
            $table->dropColumn(['insurance_claim_id', 'payer_type', 'payer_id']);
        });

        Schema::table('invoice_treatments', function (Blueprint $table) {
            $table->dropColumn([
                'covered_amount', 'insurance_amount', 'patient_amount', 
                'excluded_amount', 'coverage_percentage', 'coverage_rule_snapshot', 
                'pre_authorization_number'
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'patient_responsibility', 'insurance_responsibility', 
                'patient_paid_amount', 'insurance_paid_amount', 
                'patient_balance', 'insurance_balance'
            ]);
        });
    }
};
