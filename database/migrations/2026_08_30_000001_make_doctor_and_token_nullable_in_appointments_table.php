<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('doctor_name')->nullable()->change();
            $table->foreignId('doctor_id')->nullable()->change();
            $table->string('token_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('doctor_name')->nullable(false)->change();
            $table->foreignId('doctor_id')->nullable(false)->change();
            $table->string('token_number')->nullable(false)->change();
        });
    }
};
