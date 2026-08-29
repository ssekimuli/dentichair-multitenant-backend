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
            // Drop the foreign key first
            $table->dropForeignIdFor(\App\Models\Employee::class, 'doctor_id');
            // Drop the column
            $table->dropColumn('doctor_id');
            // Add new doctor_name column
            $table->string('doctor_name')->nullable()->after('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table) {
            // Drop the new column
            $table->dropColumn('doctor_name');
            // Recreate doctor_id foreign key
            $table->foreignIdFor(\App\Models\Employee::class, 'doctor_id')->nullable()->after('client_id');
        });
    }
};
