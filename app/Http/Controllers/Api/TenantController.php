<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TenantController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        if ($tenantContext->current()) {
            return response()->json([
                'message' => 'Tenants can only be registered from the central domain.',
            ], 403);
        }

        if (is_string($request->input('domain'))) {
            $request->merge([
                'domain' => strtolower(rtrim(trim($request->input('domain')), '.')),
            ]);
        }

        $templateConnection = config('tenancy.database.template_tenant_connection')
            ?: config('tenancy.database.central_connection');
        $templateConfig = config("database.connections.{$templateConnection}", []);
        $driver = $templateConfig['driver'] ?? null;

        if (!is_string($driver) || !array_key_exists($driver, config('tenancy.database.managers', []))) {
            return response()->json([
                'message' => 'The configured tenant database driver is not supported.',
            ], 422);
        }

        $validated = Validator::make($request->all(), [
            'domain' => [
                'required',
                'string',
                'max:253',
                'regex:/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/',
                'unique:'.config('tenancy.database.central_connection').'.domains,domain',
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'database' => ['required', 'string', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'sms_client_id' => ['nullable', 'string', 'max:4000'],
            'app_url' => ['nullable', 'url', 'max:255'],
            'email_from_name' => ['nullable', 'string', 'max:255'],
            'email_reply_to' => ['nullable', 'email', 'max:255'],
            'logo_url' => ['nullable', 'url', 'max:255'],
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:32'],
        ])->validate();

        $domain = $validated['domain'];

        $centralDomains = array_map('strtolower', config('tenancy.central_domains', []));

        if (in_array($domain, $centralDomains, true)) {
            return response()->json([
                'message' => 'A central domain cannot be registered as a tenant.',
                'errors' => ['domain' => ['Choose a domain that is not configured as a central domain.']],
            ], 422);
        }

        if ($driver !== 'sqlite' && empty($validated['username'])) {
            return response()->json([
                'message' => 'Database credentials are invalid.',
                'errors' => ['username' => ['A database username is required for this driver.']],
            ], 422);
        }

        $applicationUrl = $validated['app_url']
            ?? (app()->environment('local', 'testing') ? 'http://' : 'https://').$domain;
        $urlParts = parse_url($applicationUrl);

        if (
            !in_array($urlParts['scheme'] ?? null, ['http', 'https'], true)
            || strtolower((string) ($urlParts['host'] ?? '')) !== $domain
        ) {
            return response()->json([
                'message' => 'The application URL must use HTTP or HTTPS and match the registered domain.',
                'errors' => ['app_url' => ['The URL host must match the tenant domain.']],
            ], 422);
        }

        $defaultPort = match ($driver) {
            'pgsql' => 5432,
            default => 3306,
        };

        $tenant = DB::connection(config('tenancy.database.central_connection'))->transaction(
            function () use (
                $validated,
                $applicationUrl,
                $defaultPort,
                $domain,
                $driver,
                $templateConnection,
                $templateConfig
            ): Tenant {
                $tenant = Tenant::query()->create([
                    'status' => 'provisioning',
                    'display_name' => trim($validated['display_name']),
                    'app_url' => $applicationUrl,
                    'tenancy_db_host' => $driver === 'sqlite'
                        ? 'localhost'
                        : ($validated['host'] ?? $templateConfig['host'] ?? '127.0.0.1'),
                    'tenancy_db_port' => $driver === 'sqlite'
                        ? null
                        : (int) ($validated['port'] ?? $templateConfig['port'] ?? $defaultPort),
                    'tenancy_db_name' => $validated['database'],
                    'tenancy_db_username' => $driver === 'sqlite'
                        ? null
                        : $validated['username'],
                    'tenancy_db_password' => $driver === 'sqlite'
                        ? null
                        : ($validated['password'] ?? null),
                    'tenancy_db_connection' => $templateConnection,
                    'sms_client_id' => $validated['sms_client_id'] ?? null,
                    'email_from_name' => $validated['email_from_name'] ?? trim($validated['display_name']),
                    'email_reply_to' => $validated['email_reply_to'] ?? null,
                    'logo_url' => $validated['logo_url'] ?? null,
                    'brand_color' => $validated['brand_color'] ?? null,
                    'support_email' => $validated['support_email'] ?? null,
                    'support_phone' => $validated['support_phone'] ?? null,
                    'migration_status' => 'pending',
                ]);

                $tenant->createDomain($domain);

                return $tenant;
            }
        );

        return response()->json([
            'message' => 'Tenant registered. Run tenants:migrate to install its schema.',
            'data' => [
                'id' => $tenant->getTenantKey(),
                'domain' => $domain,
                'display_name' => $tenant->display_name,
                'app_url' => $tenant->application_url,
                'status' => $tenant->status,
                'migration_status' => $tenant->migration_status,
            ],
        ], 201);
    }
}
