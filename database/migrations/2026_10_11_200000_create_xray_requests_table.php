<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: xray_requests
     * Tracks radiology / X-ray requisition orders for patients
     */
    public function up(): void
    {
        Schema::create('xray_requests', function (Blueprint $table) {
            $table->id();

            // Client UUID for sync support
            $table->string('client_uuid')->nullable()->index();

            // Patient
            $table->unsignedBigInteger('patient_id')->index();
            $table->foreign('patient_id')
                  ->references('id')
                  ->on('patients')
                  ->onDelete('cascade');

            // Facility / Imaging Center
            $table->string('facility')->comment('Imaging Center / Radiology Facility');

            // Amount / Fee
            $table->decimal('amount', 10, 2)->default(0.00);

            // X-Ray / Scan Type
            $table->string('xray_type')->nullable()->comment('OPG, Bitewing, Periapical, CBCT, Cephalometric, etc.');

            // Tooth or Region
            $table->string('tooth_region')->nullable()->comment('Specific tooth or anatomical region');

            // Clinical Indication / Reason / Notes
            $table->text('clinical_notes')->nullable();

            // Requesting Doctor / Clinician
            $table->string('doctor_name')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable()->index();

            // Clinic Branch
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreign('branch_id')
                  ->references('id')
                  ->on('branches')
                  ->onDelete('set null');

            // Status: pending, completed, cancelled
            $table->string('status')->default('pending')->index();

            // Dates
            $table->date('request_date')->nullable()->index();
            $table->date('completed_date')->nullable();

            // Findings / Results
            $table->text('report_findings')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('xray_requests');
    }
};
