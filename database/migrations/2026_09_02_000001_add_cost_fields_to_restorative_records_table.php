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
        Schema::table('restorative_records', function (Blueprint $table) {
            $table->decimal('procedure_cost', 10, 2)->default(0)->after('notes');
            $table->decimal('material_cost', 10, 2)->default(0)->after('procedure_cost');
            $table->decimal('other_charges', 10, 2)->default(0)->after('material_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restorative_records', function (Blueprint $table) {
            $table->dropColumn(['procedure_cost', 'material_cost', 'other_charges']);
        });
    }
};
