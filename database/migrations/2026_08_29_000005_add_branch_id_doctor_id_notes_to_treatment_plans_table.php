<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('patient_id')
                ->constrained('branches')
                ->nullOnDelete();
            $table->unsignedBigInteger('doctor_id')->nullable()->after('branch_id');
            $table->text('notes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['branch_id', 'doctor_id', 'notes']);
        });
    }
};
