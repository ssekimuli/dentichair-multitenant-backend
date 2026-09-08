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
        // Widen the enum first so old values ('Sent', 'Completed') and new values coexist
        // while existing rows are remapped, then narrow to the final set.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE laboratory_orders CHANGE status status ENUM('Sent', 'Draft', 'Received', 'In Production', 'Ready for Delivery', 'Delivered', 'Completed', 'Cancelled') NOT NULL");
        }

        DB::table('laboratory_orders')->where('status', 'Sent')->update(['status' => 'Received']);
        DB::table('laboratory_orders')->where('status', 'Completed')->update(['status' => 'Delivered']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE laboratory_orders CHANGE status status ENUM('Draft', 'Received', 'In Production', 'Ready for Delivery', 'Delivered', 'Cancelled') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE laboratory_orders CHANGE status status ENUM('Sent', 'Received', 'Completed', 'Cancelled') NOT NULL");
        }
    }
};
