<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: medical_histories
     * Designed for: 1 Patient -> Many Medical / Dental Histories
     */
    public function up(): void
    {
        Schema::create('medical_histories', function (Blueprint $table) {
            $table->id();
            
            // Foreign key referencing patients table
            $table->unsignedBigInteger('patient_id')->index();
            $table->foreign('patient_id')
                  ->references('id')
                  ->on('patients')
                  ->onDelete('cascade');

            // Consultation / Examination Date
            $table->date('date')->nullable();

            // 1. Clinical Presentation
            $table->text('chief_complaint');                  // Chief complaint
            $table->text('history_complaint')->nullable();    // History of present complaint / illness

            // 2. Oral Examination
            $table->text('extra_oral_exam')->nullable();      // Extra-oral examination (TMJ, lymph nodes, symmetry)
            $table->text('intra_oral_exam')->nullable();      // Intra-oral examination (Mucosa, gingiva, teeth, tongue)

            // 3. Diagnosis & Treatment
            $table->text('diagnosis')->nullable();            // Clinical diagnosis (Provisional / Final)
            $table->text('treatment_plan')->nullable();        // Sequenced proposed treatment plan
            $table->text('treatment_given')->nullable();       // Procedures rendered during this session

            // 4. Clinical Notes & Patient Consent
            $table->boolean('consent')->default(true);        // Patient / Guardian informed consent obtained
            $table->text('consent_notes')->nullable();        // Notes or details on consent
            $table->text('treatment_note')->nullable();       // Clinical observations & post-op notes

            // Attending Clinician / Doctor
            $table->string('doctor_name')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_histories');
    }
};
