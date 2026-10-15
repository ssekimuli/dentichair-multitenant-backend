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
        Schema::table('global_settings', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('address');
            $table->string('email')->nullable()->after('phone');
            $table->string('subtitle')->nullable()->after('email'); // facility subtitle/tagline
            $table->string('tax_number')->nullable()->after('subtitle'); // TIN/VAT number
            $table->string('receipt_prefix')->nullable()->default('REC-')->after('tax_number');
            $table->string('currency')->nullable()->default('USD')->after('receipt_prefix');
            $table->text('receipt_footer')->nullable()->after('currency'); // footer note / terms & conditions
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'email',
                'subtitle',
                'tax_number',
                'receipt_prefix',
                'currency',
                'receipt_footer',
            ]);
        });
    }
};
