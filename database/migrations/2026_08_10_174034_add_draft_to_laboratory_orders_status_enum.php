<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE laboratory_orders CHANGE status status ENUM('Draft', 'Received', 'In Production', 'Ready for Delivery', 'Delivered', 'Cancelled') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE laboratory_orders CHANGE status status ENUM('Received', 'In Production', 'Ready for Delivery', 'Delivered', 'Cancelled') NOT NULL");
    }
};
