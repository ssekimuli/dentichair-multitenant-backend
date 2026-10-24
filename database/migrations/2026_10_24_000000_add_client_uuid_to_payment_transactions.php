<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_transactions') && !Schema::hasColumn('payment_transactions', 'client_uuid')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->uuid('client_uuid')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_transactions') && Schema::hasColumn('payment_transactions', 'client_uuid')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->dropColumn('client_uuid');
            });
        }
    }
};
