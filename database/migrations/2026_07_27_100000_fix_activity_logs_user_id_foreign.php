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
        if (config('database.default') === 'sqlite') {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            if (config('database.default') !== 'sqlite') {
                $table->dropForeign(['user_id']);
            }
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('employees')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            if (config('database.default') !== 'sqlite') {
                $table->dropForeign(['user_id']);
            }
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }
};
