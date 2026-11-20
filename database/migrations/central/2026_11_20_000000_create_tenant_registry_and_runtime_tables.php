<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('tenancy.database.central_connection');
        $schema = Schema::connection($connection);

        $schema->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('display_name');
            $table->string('status')->default('provisioning')->index();
            $table->text('sms_client_id')->nullable();
            $table->string('app_url')->nullable();
            $table->string('email_from_name')->nullable();
            $table->string('email_reply_to')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->string('migration_status')->default('pending');
            $table->timestamp('last_migrated_at')->nullable();
            $table->text('migration_error')->nullable();
            $table->string('tenancy_db_name')->nullable();
            $table->string('tenancy_db_host')->nullable();
            $table->unsignedSmallInteger('tenancy_db_port')->nullable();
            $table->string('tenancy_db_username')->nullable();
            $table->text('tenancy_db_password')->nullable();
            $table->string('tenancy_db_connection')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        $schema->create('domains', function (Blueprint $table) {
            $table->increments('id');
            $table->string('domain', 255)->unique();
            $table->string('tenant_id');
            $table->timestamps();
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        if (!$schema->hasTable('sessions')) {
            $schema->create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (!$schema->hasTable('jobs')) {
            $schema->create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!$schema->hasTable('job_batches')) {
            $schema->create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (!$schema->hasTable('failed_jobs')) {
            $schema->create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        if (!$schema->hasTable('cache')) {
            $schema->create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (!$schema->hasTable('cache_locks')) {
            $schema->create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        $connection = config('tenancy.database.central_connection');

        Schema::connection($connection)->dropIfExists('domains');
        Schema::connection($connection)->dropIfExists('tenants');
    }
};
