<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records how a patient was created when it came from the public web forms
     * (web_booking, web_self_register). Null for patients created inside the app.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('registration_source', 30)->nullable()->after('status');
            $table->index(['status', 'registration_source'], 'patients_status_registration_source_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_status_registration_source_index');
            $table->dropColumn('registration_source');
        });
    }
};
