<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->enum('status', ['Pending', 'Proposed', 'Completed'])->default('Proposed')->change();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->enum('status', ['Proposed', 'Completed'])->default('Proposed')->change();
        });
    }
};
