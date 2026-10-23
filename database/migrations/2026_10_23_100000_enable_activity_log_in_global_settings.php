<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update the column default on global_settings table to true
        Schema::table('global_settings', function (Blueprint $table) {
            $table->boolean('activity_log_enabled')->default(true)->change();
        });

        // 2. Set activity_log_enabled to true for all existing global settings records
        DB::table('global_settings')->update(['activity_log_enabled' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Revert the column default on global_settings table to false
        Schema::table('global_settings', function (Blueprint $table) {
            $table->boolean('activity_log_enabled')->default(false)->change();
        });

        // 2. Revert activity_log_enabled to false
        DB::table('global_settings')->update(['activity_log_enabled' => false]);
    }
};
