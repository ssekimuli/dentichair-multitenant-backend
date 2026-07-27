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
            $table->date('received_date')->nullable();
            $table->string('shade')->nullable();
            $table->string('material')->nullable();
            $table->text('instructions')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->string('invoice_number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            $table->dropColumn(['received_date', 'shade', 'material', 'instructions', 'cost', 'invoice_number']);
        });
    }
};
