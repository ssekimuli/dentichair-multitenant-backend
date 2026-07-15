<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('medicine_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained('medicines')->onDelete('cascade');
            $table->string('unit_name'); // e.g. Tablet, Box, Strip, Bottle
            $table->decimal('price', 10, 2);
            $table->integer('stock_quantity')->default(0);
            $table->timestamps();
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->string('patient_name');
            $table->foreignId('doctor_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('doctor_name')->nullable();
            $table->date('prescription_date');
            $table->enum('status', ['Pending', 'Dispensed', 'Billed'])->default('Pending');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->onDelete('cascade');
            $table->foreignId('medicine_id')->constrained('medicines')->onDelete('cascade');
            $table->foreignId('medicine_unit_id')->constrained('medicine_units')->onDelete('cascade');
            $table->string('dosage'); // e.g. "1x3 daily after food"
            $table->integer('quantity');
            $table->decimal('price', 10, 2); // Price of unit when prescribed
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->json('medicines')->nullable();
        });
    }

    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('medicines');
        });

        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('medicine_units');
        Schema::dropIfExists('medicines');
    }
};
