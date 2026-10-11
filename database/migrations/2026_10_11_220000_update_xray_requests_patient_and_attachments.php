<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Make patient_id nullable natively
        DB::statement('ALTER TABLE xray_requests MODIFY patient_id BIGINT UNSIGNED NULL');

        Schema::table('xray_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('xray_requests', 'patient')) {
                $table->string('patient')->nullable()->after('client_uuid');
            }
            if (!Schema::hasColumn('xray_requests', 'attachments')) {
                $table->longText('attachments')->nullable()->after('report_findings');
            }
        });
    }

    public function down(): void
    {
        Schema::table('xray_requests', function (Blueprint $table) {
            if (Schema::hasColumn('xray_requests', 'patient')) {
                $table->dropColumn('patient');
            }
            if (Schema::hasColumn('xray_requests', 'attachments')) {
                $table->dropColumn('attachments');
            }
        });
    }
};
