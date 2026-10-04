<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $tables = [
        'invoices',
        'payments',
        'prescriptions',
        'pharmacy_products',
        'pharmacy_sales',
        'dental_records',
        'restorative_records',
        'endodontic_records',
        'orthodontic_records',
        'periodontal_records',
        'soft_tissue_records',
        'patient_images',
        'procedures',
        'expenses',
        'documents'
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    if (!Schema::hasColumn($table->getTable(), 'client_uuid')) {
                        $table->uuid('client_uuid')->nullable()->unique();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    if (Schema::hasColumn($table->getTable(), 'client_uuid')) {
                        $table->dropColumn('client_uuid');
                    }
                });
            }
        }
    }
};
