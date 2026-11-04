<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('patient_referrals')) {
            Schema::create('patient_referrals', function (Blueprint $table) {
                $table->id();
                $table->string('client_uuid')->nullable()->index();
                $table->unsignedBigInteger('patient_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('patient_name')->nullable();
                $table->string('patient_number')->nullable();
                $table->string('sex', 50)->nullable();
                $table->string('age', 50)->nullable();
                $table->date('first_visit_date')->nullable();
                $table->date('referral_date')->nullable();
                $table->string('referred_to')->nullable();
                $table->string('target_email')->nullable();
                $table->string('target_phone')->nullable();
                $table->string('from_health_unit')->nullable();
                $table->date('unit_date')->nullable();
                $table->text('history_and_symptoms')->nullable();
                $table->text('investigations_done')->nullable();
                $table->text('diagnosis')->nullable();
                $table->text('treatment_given')->nullable();
                $table->text('reason_for_referral')->nullable();
                $table->string('clinician_name')->nullable();
                $table->string('clinician_phone')->nullable();
                $table->string('clinician_signature')->nullable();
                $table->text('further_investigations')->nullable();
                $table->text('referral_site_diagnosis')->nullable();
                $table->text('referral_site_treatment')->nullable();
                $table->text('surveillance_continued')->nullable();
                $table->text('remarks')->nullable();
                $table->string('referral_site_clinician')->nullable();
                $table->string('referral_site_phone')->nullable();
                $table->string('referral_site_signature')->nullable();
                $table->string('status', 50)->default('referred');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_referrals');
    }
};

