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
        Schema::table('orthodontic_records', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->default(0)->nullable()->after('treatment_phase');
            $table->string('period')->nullable()->after('amount');
            $table->text('procedures')->nullable()->after('period');
            $table->unsignedBigInteger('invoice_id')->nullable()->after('procedures');
            $table->unsignedBigInteger('medical_history_id')->nullable()->after('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orthodontic_records', function (Blueprint $table) {
            $table->dropColumn([
                'amount',
                'period',
                'procedures',
                'invoice_id',
                'medical_history_id',
            ]);
        });
    }
};
