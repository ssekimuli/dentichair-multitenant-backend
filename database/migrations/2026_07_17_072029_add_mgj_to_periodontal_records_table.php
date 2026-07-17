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
        Schema::table('periodontal_records', function (Blueprint $table) {
            $table->integer('mgj')->nullable()->after('recession');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('periodontal_records', function (Blueprint $table) {
            $table->dropColumn('mgj');
        });
    }
};
