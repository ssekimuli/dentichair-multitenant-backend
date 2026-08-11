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
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Invoice::class);
            $table->dropColumn('invoice_id');
        });
    }
};
