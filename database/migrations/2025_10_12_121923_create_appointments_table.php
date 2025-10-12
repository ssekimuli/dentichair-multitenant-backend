<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('avatar')->nullable();
            $table->string('email')->unique();
            $table->string('role');
            $table->enum('status', ['Active', 'On Leave'])->default('Active');
            $table->decimal('salary', 10, 2);
            $table->date('hire_date');
            $table->string('branch_id');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('patient_name');
            $table->string('patient_id');
            $table->string('doctor_name');
            $table->string('doctor_id');
            $table->string('treatment');
            $table->enum('status', ['Scheduled', 'Completed', 'Cancelled', 'Waiting']);
            $table->date('date');
            $table->string('time');
            $table->string('branch_id');
            $table->string('token_number');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('doctor_id')->references('id')->on('employees');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('patient_name');
            $table->string('patient_id');
            $table->decimal('amount', 10, 2);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->enum('status', ['Pending', 'Paid', 'Overdue']);
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('branch_id');
            $table->string('payment_method')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('invoice_treatments', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_id');
            $table->string('procedure');
            $table->decimal('cost', 10, 2);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices');
        });

        Schema::create('invoice_other_charges', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_id');
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices');
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('invoice_id');
            $table->date('date');
            $table->decimal('amount', 10, 2);
            $table->string('method');
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('invoice_other_charges');
        Schema::dropIfExists('invoice_treatments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('employees');
    }
};