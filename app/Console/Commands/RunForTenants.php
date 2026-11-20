<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Throwable;

class RunForTenants extends Command
{
    protected $signature = 'app:tenants:run {command : Scheduled Artisan command to run for each active tenant}';

    protected $description = 'Run an Artisan command once for every active tenant';

    public function handle(TenantContext $tenantContext): int
    {
        $command = (string) $this->argument('command');

        if (!config('tenancy.enabled')) {
            return $this->call($command);
        }

        $failed = false;
        $tenants = Tenant::query()->where('status', 'active')->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            $domain = $tenant->domains()->value('domain') ?? $tenant->getTenantKey();
            $this->line("Running {$command} for {$domain}...");

            try {
                $exitCode = $tenantContext->run(
                    $tenant,
                    fn (): int => $this->call($command)
                );

                $failed = $failed || $exitCode !== self::SUCCESS;
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Command failed for {$domain}: {$exception->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
