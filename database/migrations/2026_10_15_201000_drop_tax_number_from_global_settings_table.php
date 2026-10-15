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
        if (Schema::hasColumn('global_settings', 'tax_number')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->dropColumn('tax_number');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('global_settings', 'tax_number')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->string('tax_number')->nullable()->after('subtitle');
            });
        }
    }
};
