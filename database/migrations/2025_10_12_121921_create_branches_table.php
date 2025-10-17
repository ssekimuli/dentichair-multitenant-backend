<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); // This creates a bigInteger auto-increment primary key
            $table->string('name');
            $table->string('address');
            $table->timestamps();
        });

        Schema::create('treatments', function (Blueprint $table) {
            $table->id();
            $table->string('procedure');
            $table->decimal('cost', 10, 2);
            $table->timestamps();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('avatar')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable();
            $table->date('date_of_birth');
            $table->date('last_visit')->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->foreignId('branch_id')->constrained('branches');
            $table->text('history')->nullable();
            $table->json('occlusal_records')->nullable();
            $table->timestamps();
        });

        Schema::create('dental_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id'); // Changed to foreignId
            $table->integer('tooth_id');
            $table->string('condition');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
        });

        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id'); // Changed to foreignId
            $table->string('procedure');
            $table->integer('tooth')->nullable();
            $table->enum('status', ['Proposed', 'Completed'])->default('Proposed');
            $table->decimal('cost', 10, 2);
            $table->date('date');
            $table->string('invoice_id')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
        });

        Schema::create('patient_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id'); // Changed to foreignId
            $table->string('url');
            $table->string('description');
            $table->date('date');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
        });

        Schema::create('periodontal_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id'); // Changed to foreignId
            $table->integer('tooth_id');
            $table->json('pockets');
            $table->integer('recession');
            $table->string('mobility');
            $table->string('furcation')->nullable();
            $table->json('conditions');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
        });

        Schema::create('soft_tissue_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id'); // Changed to foreignId
            $table->date('date');
            $table->string('location');
            $table->string('type');
            $table->text('description');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
        });

        Schema::create('orthodontic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id'); // Changed to foreignId
            $table->date('date');
            $table->string('appliance');
            $table->string('wire_size')->nullable();
            $table->json('bracket_changes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
        });
    }

    public function down()
    {
        Schema::dropIfExists('orthodontic_records');
        Schema::dropIfExists('soft_tissue_records');
        Schema::dropIfExists('periodontal_records');
        Schema::dropIfExists('patient_images');
        Schema::dropIfExists('treatment_plans');
        Schema::dropIfExists('dental_records');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('treatments');
        Schema::dropIfExists('branches');
    }
};
