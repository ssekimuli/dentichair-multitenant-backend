<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AddTenant extends Command
{
    protected $signature = 'tenants:add
        {domain : Registered tenant hostname}
        {displayName : Tenant display name}
        {--host=127.0.0.1 : Tenant database host}
        {--port= : Tenant database port}
        {--database= : Tenant database name or SQLite file}
        {--username= : Tenant database username}
        {--url= : Tenant application URL}
        {--email-reply-to= : Tenant reply-to address}
        {--logo-url= : Tenant logo URL}
        {--brand-color= : Tenant brand color in #RRGGBB format}
        {--support-email= : Tenant support email}
        {--support-phone= : Tenant support phone}';

    protected $description = 'Register a tenant and its database and branding settings';

    public function handle(): int
    {
        $domain = strtolower(rtrim(trim($this->argument('domain')), '.'));
        $displayName = trim($this->argument('displayName'));
        $database = (string) $this->option('database');
        $templateConnection = config('tenancy.database.template_tenant_connection')
            ?: config('tenancy.database.central_connection');
        $driver = config("database.connections.{$templateConnection}.driver");

        if (!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $domain)) {
            $this->error('Provide a valid hostname.');

            return self::FAILURE;
        }

        if ($displayName === '' || !in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            $this->error('A display name and supported tenant database template connection are required.');

            return self::FAILURE;
        }

        if ($database === '') {
            $this->error('Provide the already-provisioned tenant database with --database.');

            return self::FAILURE;
        }

        if ($driver !== 'sqlite' && trim((string) $this->option('username')) === '') {
            $this->error('Provide a tenant database username with --username.');

            return self::FAILURE;
        }

        if (Tenant::query()->whereHas('domains', function ($query) use ($domain): void {
            $query->where('domain', $domain);
        })->exists()) {
            $this->error("A tenant is already registered for {$domain}.");

            return self::FAILURE;
        }

        $brandColor = $this->option('brand-color');

        if ($brandColor !== null && !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor)) {
            $this->error('The brand color must use #RRGGBB format.');

            return self::FAILURE;
        }

        $replyTo = $this->option('email-reply-to');
        $supportEmail = $this->option('support-email');

        if (($replyTo && !filter_var($replyTo, FILTER_VALIDATE_EMAIL))
            || ($supportEmail && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL))) {
            $this->error('Provide valid reply-to and support email addresses.');

            return self::FAILURE;
        }

        $applicationUrl = $this->option('url')
            ?: (app()->environment('local', 'testing') ? 'http://' : 'https://').$domain;
        $applicationUrlHost = parse_url($applicationUrl, PHP_URL_HOST);

        if (!in_array(parse_url($applicationUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || strtolower((string) $applicationUrlHost) !== $domain) {
            $this->error('The application URL must use HTTP or HTTPS and match the registered hostname.');

            return self::FAILURE;
        }

        $databasePassword = $driver === 'sqlite' ? null : $this->secret('Tenant database password');
        $smsClientId = $this->secret('Tenant SMS client ID (leave blank if not configured)');
        $defaultPort = match ($driver) {
            'pgsql' => 5432,
            default => 3306,
        };

        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use (
            $domain,
            $displayName,
            $applicationUrl,
            $database,
            $driver,
            $databasePassword,
            $smsClientId,
            $defaultPort,
            $replyTo,
            $brandColor,
            $supportEmail
        ): void {
            $tenant = Tenant::query()->create([
                'status' => 'provisioning',
                'display_name' => $displayName,
                'app_url' => $applicationUrl,
                'tenancy_db_host' => $driver === 'sqlite' ? 'localhost' : $this->option('host'),
                'tenancy_db_port' => $driver === 'sqlite' ? null : (int) ($this->option('port') ?: $defaultPort),
                'tenancy_db_name' => $database,
                'tenancy_db_username' => $driver === 'sqlite' ? null : $this->option('username'),
                'tenancy_db_password' => $databasePassword ?: null,
                'tenancy_db_connection' => config('tenancy.database.template_tenant_connection'),
                'sms_client_id' => $smsClientId ?: null,
                'email_from_name' => $displayName,
                'email_reply_to' => $replyTo,
                'logo_url' => $this->option('logo-url'),
                'brand_color' => $brandColor,
                'support_email' => $supportEmail,
                'support_phone' => $this->option('support-phone'),
                'migration_status' => 'pending',
            ]);

            $tenant->createDomain($domain);
        });

        $this->info("Tenant {$domain} registered. Run tenants:migrate to install its schema.");

        return self::SUCCESS;
    }
}
