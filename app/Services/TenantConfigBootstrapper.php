<?php

namespace App\Services;

use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class TenantConfigBootstrapper implements TenancyBootstrapper
{
    private ?array $originalConfig = null;

    public function __construct(private Application $app)
    {
    }

    public function bootstrap(\Stancl\Tenancy\Contracts\Tenant $tenant): void
    {
        $this->originalConfig ??= [
            'app.name' => $this->app['config']->get('app.name'),
            'app.url' => $this->app['config']->get('app.url'),
        ];

        $this->app['config']->set([
            'app.name' => $tenant->getAttribute('display_name'),
            'app.url' => $tenant->getAttribute('application_url'),
        ]);
    }

    public function revert(): void
    {
        if ($this->originalConfig === null) {
            return;
        }

        $this->app['config']->set($this->originalConfig);
        $this->originalConfig = null;
    }
}
