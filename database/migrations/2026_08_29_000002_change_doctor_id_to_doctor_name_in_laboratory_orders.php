<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }
        Schema::disableForeignKeyConstraints();
        $foreignKeys = DB::getDriverName() !== 'sqlite'
            ? collect(Schema::getForeignKeys('laboratory_orders'))->pluck('name')->all()
            : [];
        Schema::table('laboratory_orders', function (Blueprint $table) use ($foreignKeys) {
            // Drop the foreign key constraint first if supported
            if (DB::getDriverName() !== 'sqlite') {
                if (in_array('laboratory_orders_doctor_id_foreign', $foreignKeys)) {
                    $table->dropForeign('laboratory_orders_doctor_id_foreign');
                }
                if (Schema::hasColumn('laboratory_orders', 'doctor_id')) {
                    $table->dropColumn('doctor_id');
                }
            }
            // Add new doctor_name column
            if (!Schema::hasColumn('laboratory_orders', 'doctor_name')) {
                $table->string('doctor_name')->nullable()->after('client_id');
            }
        });
        Schema::enableForeignKeyConstraints();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
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
