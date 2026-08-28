<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_service_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_service_id')->constrained()->onDelete('cascade');
            $table->string('lab');
            $table->decimal('base_price', 10, 2);
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_service_prices');
    }
};
