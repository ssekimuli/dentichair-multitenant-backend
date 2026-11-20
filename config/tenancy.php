<?php

return [
    'enabled' => (bool) env('TENANCY_ENABLED', false),
    'tenant_model' => App\Models\Tenant::class,
    'id_generator' => Stancl\Tenancy\UUIDGenerator::class,
    'domain_model' => Stancl\Tenancy\Database\Models\Domain::class,
    'central_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', 'localhost,127.0.0.1'))
    ))),
    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
        App\Services\TenantConfigBootstrapper::class,
    ],
    'database' => [
        'central_connection' => env('TENANCY_REGISTRY_CONNECTION', 'tenant_registry'),
        'template_tenant_connection' => env('TENANCY_TEMPLATE_CONNECTION', 'tenant_registry'),
        'prefix' => 'tenant_',
        'suffix' => '',
        'managers' => [
            'sqlite' => Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager::class,
            'mysql' => Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager::class,
            'mariadb' => Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager::class,
            'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
        ],
    ],
    'cache' => [
        'tag_base' => 'tenant',
    ],
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],
    'features' => [],
    'routes' => false,
    'migration_parameters' => [
        '--database' => 'tenant',
        '--force' => true,
        '--path' => [database_path('migrations')],
        '--realpath' => true,
    ],
    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\DatabaseSeeder',
        '--force' => true,
    ],
];
