<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('chart_data', function (Blueprint $table) {
            $table->id();
            $table->string('month');
            $table->decimal('revenue', 10, 2);
            $table->string('branch_id');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('payrolls', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('employee_id');
            $table->string('employee_name');
            $table->string('pay_period');
            $table->decimal('gross_pay', 10, 2);
            $table->decimal('deductions', 10, 2);
            $table->decimal('net_pay', 10, 2);
            $table->date('pay_date');
            $table->enum('status', ['Paid', 'Pending']);
            $table->string('branch_id');
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('employee_id');
            $table->string('employee_name');
            $table->date('date');
            $table->enum('status', ['Present', 'Absent', 'On Leave']);
            $table->string('check_in')->nullable();
            $table->string('check_out')->nullable();
            $table->string('branch_id');
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('supplier');
            $table->integer('stock');
            $table->integer('reorder_level');
            $table->decimal('price', 10, 2);
            $table->enum('status', ['In Stock', 'Low Stock', 'Out of Stock']);
            $table->string('branch_id');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('laboratory_orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('patient_id');
            $table->string('patient_name');
            $table->string('lab');
            $table->string('item');
            $table->integer('tooth')->nullable();
            $table->enum('status', ['Sent', 'Received', 'Completed', 'Cancelled']);
            $table->date('sent_date');
            $table->date('due_date');
            $table->string('branch_id');
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('employee_id');
            $table->string('employee_name');
            $table->string('leave_type');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->enum('status', ['Pending', 'Approved', 'Rejected']);
            $table->string('branch_id');
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('staff_advances', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('employee_id');
            $table->string('employee_name');
            $table->decimal('amount', 10, 2);
            $table->date('date');
            $table->text('reason');
            $table->enum('status', ['Pending', 'Approved', 'Rejected']);
            $table->string('branch_id');
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('branch_id');
            $table->date('date');
            $table->string('category');
            $table->text('description');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->json('permissions');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('staff_advances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('laboratory_orders');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('chart_data');
    }
};