<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medical_histories') && !Schema::hasColumn('medical_histories', 'client_uuid')) {
            Schema::table('medical_histories', function (Blueprint $table) {
                $table->uuid('client_uuid')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('medical_histories') && Schema::hasColumn('medical_histories', 'client_uuid')) {
            Schema::table('medical_histories', function (Blueprint $table) {
                $table->dropColumn('client_uuid');
            });
        }
    }
};
