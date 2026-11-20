<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class TenantContext
{
    public function current(): ?Tenant
    {
        return tenancy()->initialized ? tenancy()->tenant : null;
    }

    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previousTenant = $this->current();

        try {
            tenancy()->initialize($tenant);

            return $callback();
        } finally {
            if ($previousTenant) {
                tenancy()->initialize($previousTenant);
            } elseif (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    public function cacheKey(string $key): string
    {
        $tenant = $this->current();

        return $tenant
            ? 'tenant:'.$tenant->getTenantKey().':'.$key
            : $key;
    }

    public function clientId(): ?string
    {
        $tenant = $this->current();

        return $tenant ? $tenant->sms_client_id : config('services.sms.client_id');
    }

    public function applicationUrl(): string
    {
        return $this->current()?->application_url ?: config('app.url');
    }

    public function storagePath(string $path): string
    {
        $tenant = $this->current();

        if (! $tenant) {
            return $path;
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');

        if (in_array('..', explode('/', $relativePath), true)) {
            throw new InvalidArgumentException('Tenant storage paths cannot traverse parent directories.');
        }

        return 'tenants/'.$tenant->getTenantKey().'/'.$relativePath;
    }

    public function resolveById(int|string $id): Tenant
    {
        $tenant = tenancy()->find($id);

        if (! $tenant || $tenant->status !== 'active') {
            Log::warning('A queued task referenced a missing or inactive tenant.', [
                'tenant_id' => $id,
            ]);

            throw new RuntimeException('The tenant for this queued task is unavailable.');
        }

        return $tenant;
    }
}
