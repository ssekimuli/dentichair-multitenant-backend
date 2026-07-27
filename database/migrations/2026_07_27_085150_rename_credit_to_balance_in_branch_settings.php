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
        Schema::table('branch_settings', function (Blueprint $table) {
            $table->renameColumn('sms_credit', 'sms_balance');
            $table->renameColumn('whatsapp_credit', 'whatsapp_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_settings', function (Blueprint $table) {
            $table->renameColumn('sms_balance', 'sms_credit');
            $table->renameColumn('whatsapp_balance', 'whatsapp_credit');
        });
    }
};
