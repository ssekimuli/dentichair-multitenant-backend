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
        Schema::create('endodontic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id');
            $table->date('date');
            $table->integer('tooth_id');
            $table->string('pulpal_diagnosis');
            $table->string('periapical_diagnosis');
            $table->json('canals');
            $table->string('obturation_material')->nullable();
            $table->string('post_type')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('endodontic_records');
    }
};
