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
        if (Schema::hasTable('global_settings') && !Schema::hasColumn('global_settings', 'logo')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->string('logo', 1000)->nullable()->after('clinic_name');
            });
        }

        if (Schema::hasTable('branches') && !Schema::hasColumn('branches', 'logo')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->string('logo', 1000)->nullable()->after('address');
            });
        }

        if (Schema::hasTable('businesses') && !Schema::hasColumn('businesses', 'logo')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->string('logo', 1000)->nullable()->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('global_settings') && Schema::hasColumn('global_settings', 'logo')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->dropColumn('logo');
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'logo')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('logo');
            });
        }

        if (Schema::hasTable('businesses') && Schema::hasColumn('businesses', 'logo')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn('logo');
            });
        }
    }
};
