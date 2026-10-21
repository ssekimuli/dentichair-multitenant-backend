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
        if (!Schema::hasTable('import_jobs')) {
            Schema::create('import_jobs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('table_name')->index();
                $table->string('file_name');
                $table->string('file_path')->nullable();
                $table->string('file_type', 20)->default('csv');
                $table->string('sheet_name')->nullable();
                $table->string('status', 30)->default('pending')->index(); // pending, queued, processing, completed, failed, cancelled
                $table->string('mode', 30)->default('insert'); // insert, skip_duplicates, update_existing, upsert
                $table->string('error_mode', 20)->default('partial'); // partial, atomic
                $table->string('update_key', 100)->nullable();
                $table->integer('total_rows')->default(0);
                $table->integer('processed_rows')->default(0);
                $table->integer('imported_count')->default(0);
                $table->integer('updated_count')->default(0);
                $table->integer('skipped_count')->default(0);
                $table->integer('failed_count')->default(0);
                $table->json('mapping')->nullable();
                $table->json('options')->nullable();
                $table->text('error_summary')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('import_row_errors')) {
            Schema::create('import_row_errors', function (Blueprint $table) {
                $table->id();
                $table->uuid('import_job_id')->index();
                $table->integer('row_number')->index();
                $table->string('source_column')->nullable();
                $table->string('destination_field')->nullable();
                $table->string('error_code', 50)->default('VALIDATION_FAILED');
                $table->text('error_message');
                $table->json('raw_data')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('import_job_id')
                    ->references('id')
                    ->on('import_jobs')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_row_errors');
        Schema::dropIfExists('import_jobs');
    }
};
