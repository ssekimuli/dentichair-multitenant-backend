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
            $table->string('appliance_location')->nullable();
            $table->string('treatment_phase')->nullable();
            $table->string('wire_size_upper')->nullable();
            $table->string('wire_size_lower')->nullable();
            $table->string('archwire_material')->nullable();
            $table->string('elastics')->nullable();
            $table->string('compliance')->nullable();
            $table->string('oral_hygiene')->nullable();
            $table->json('broken_brackets')->nullable();
            $table->string('next_visit_goals')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orthodontic_records', function (Blueprint $table) {
            $table->dropColumn([
                'appliance_location',
                'treatment_phase',
                'wire_size_upper',
                'wire_size_lower',
                'archwire_material',
                'elastics',
                'compliance',
                'oral_hygiene',
                'broken_brackets',
                'next_visit_goals'
            ]);
        });
    }
};
