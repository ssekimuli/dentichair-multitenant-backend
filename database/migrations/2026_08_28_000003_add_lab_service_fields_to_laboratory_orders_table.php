<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->foreignId('lab_service_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('applied_price', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\LabService::class);
            $table->dropColumn('lab_service_id');
            $table->dropColumn('applied_price');
        });
    }
};
