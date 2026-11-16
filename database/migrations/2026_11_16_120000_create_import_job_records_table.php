<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which rows each import job created, so a job can be undone.
     */
    public function up(): void
    {
        if (!Schema::hasTable('import_job_records')) {
            Schema::create('import_job_records', function (Blueprint $table) {
                $table->id();
                $table->uuid('import_job_id')->index();
                $table->string('table_name', 100);
                $table->unsignedBigInteger('record_id');
                $table->string('action', 20)->default('created');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['import_job_id', 'table_name']);
                $table->foreign('import_job_id')
                    ->references('id')
                    ->on('import_jobs')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_job_records');
    }
};
