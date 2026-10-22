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
        if (Schema::hasTable('global_settings') && !Schema::hasColumn('global_settings', 'qr_code_info_data')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->text('qr_code_info_data')->nullable()->after('receipt_footer');
            });
        }

        if (Schema::hasTable('branches') && !Schema::hasColumn('branches', 'qr_code_info_data')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->text('qr_code_info_data')->nullable()->after('logo');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('global_settings') && Schema::hasColumn('global_settings', 'qr_code_info_data')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->dropColumn('qr_code_info_data');
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'qr_code_info_data')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('qr_code_info_data');
            });
        }
    }
};
