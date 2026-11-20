<?php

namespace Tests\Feature;

use App\Http\Controllers\ImportController;
use App\Models\Tenant;
use App\Services\CloudflareR2Service;
use App\Services\NotificationService;
use App\Services\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    private array $tenantDatabaseFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'app.system_key' => 'tenant-registration-test-key',
            'tenancy.enabled' => true,
        ]);

        $migrationPath = database_path('migrations/central');
        $exitCode = Artisan::call('migrate', [
            '--database' => config('tenancy.database.central_connection'),
            '--path' => $migrationPath,
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');

        foreach ($this->tenantDatabaseFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_tenant_secrets_are_encrypted_in_the_central_registry(): void
    {
        $tenant = $this->createTenant('clinic-one.test');

        $storedSmsClientId = DB::connection('tenant_registry')
            ->table('tenants')
            ->where('id', $tenant->getTenantKey())
            ->value('sms_client_id');

        $this->assertNotSame('sms-secret', $storedSmsClientId);
        $this->assertSame('sms-secret', $tenant->sms_client_id);
    }

    public function test_tenant_resolution_switches_database_and_ends_tenancy_after_request(): void
    {
        $tenant = $this->createTenant('clinic-one.test');

        Route::middleware('api')->get('/__tenant-check', function () {
            return response()->json([
                'tenant_id' => tenancy()->tenant->getTenantKey(),
                'connection' => DB::getDefaultConnection(),
                'driver' => DB::connection()->getDriverName(),
                'configured_database' => config('database.connections.tenant.database'),
                'database' => DB::connection()->getDatabaseName(),
            ]);
        });

        $response = $this->get('http://clinic-one.test/__tenant-check');

        $response->assertOk()
            ->assertJson([
                'tenant_id' => $tenant->getTenantKey(),
                'connection' => 'tenant',
                'driver' => 'sqlite',
                'configured_database' => database_path($tenant->tenancy_db_name),
                'database' => database_path($tenant->tenancy_db_name),
            ]);

        $this->assertFalse(tenancy()->initialized);
        $this->assertSame(config('tenancy.database.central_connection'), DB::getDefaultConnection());
    }

    public function test_unregistered_and_inactive_domains_are_rejected(): void
    {
        $this->createTenant('inactive.test', 'provisioning');

        Route::middleware('api')->get('/__tenant-check', fn () => response()->noContent());

        $this->get('http://unknown.test/__tenant-check')->assertNotFound();
        $this->get('http://inactive.test/__tenant-check')->assertNotFound();
    }

    public function test_sms_and_email_branding_use_the_current_tenant(): void
    {
        $tenant = $this->createTenant('clinic-one.test');
        $tenant->update([
            'email_from_name' => 'Clinic Support',
            'email_reply_to' => 'reply@clinic-one.test',
        ]);
        $email = (new Email)->from(new Address('sender@example.test', 'Default Sender'));

        $tenantData = app(TenantContext::class)->run($tenant, function () use ($email): array {
            Event::dispatch(new MessageSending($email));

            return [
                'sms_client_id' => app(TenantContext::class)->clientId(),
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'email' => app(Markdown::class)->render('emails.patient-welcome', [
                    'name' => 'Test Patient',
                    'email' => 'patient@example.test',
                    'branchName' => 'Main',
                ])->toHtml(),
                'from_name' => $email->getFrom()[0]->getName(),
                'reply_to' => $email->getReplyTo()[0]->getAddress(),
            ];
        });

        $this->assertSame('sms-secret', $tenantData['sms_client_id']);
        $this->assertSame($tenant->display_name, $tenantData['app_name']);
        $this->assertSame($tenant->application_url, $tenantData['app_url']);
        $this->assertStringContainsString($tenant->display_name, $tenantData['email']);
        $this->assertSame('Clinic Support', $tenantData['from_name']);
        $this->assertSame('reply@clinic-one.test', $tenantData['reply_to']);
        $this->assertSame(config('app.url'), app('config')->get('app.url'));
    }

    public function test_sms_does_not_report_success_when_the_tenant_has_no_client_id(): void
    {
        $tenant = $this->createTenant('no-sms.test');
        $tenant->update(['sms_client_id' => null]);

        $result = app(TenantContext::class)->run(
            $tenant,
            fn (): array => NotificationService::sendSms('+10000000000', 'Test message')
        );

        $this->assertFalse($result['success']);
        $this->assertSame('SMS client ID is not configured for this tenant', $result['message']);
    }

    public function test_uploaded_r2_files_are_stored_under_the_tenant_folder(): void
    {
        $tenant = $this->createTenant('clinic-one.test');
        $tenantFolder = 'tenants/'.$tenant->getTenantKey();
        Storage::fake('r2');
        config(['filesystems.disks.r2.url' => 'https://cdn.example.test']);

        try {
            $url = app(TenantContext::class)->run(
                $tenant,
                fn (): string => app(CloudflareR2Service::class)->upload(
                    UploadedFile::fake()->createWithContent('logo.png', 'logo'),
                    'logos',
                    'clinic-logo'
                )
            );

            $path = $tenantFolder.'/logos/clinic-logo.png';
            Storage::disk('r2')->assertExists($path);
            $this->assertSame('https://cdn.example.test/'.$path, $url);
        } finally {
            Storage::disk('r2')->deleteDirectory($tenantFolder);
        }
    }

    public function test_import_rejects_file_paths_outside_the_current_tenant_folder(): void
    {
        $tenant = $this->createTenant('clinic-one.test');
        Route::middleware('api')->post('/__tenant-import', [ImportController::class, 'startImport']);

        $this->postJson('http://clinic-one.test/__tenant-import', [
            'file_path' => 'tenants/another-tenant/imports/file.csv',
            'type' => 'patients',
            'mapping' => ['first_name' => 'Name'],
        ])->assertNotFound();

        $this->postJson('http://clinic-one.test/__tenant-import', [
            'file_path' => 'tenants/'.$tenant->getTenantKey().'/imports/../file.csv',
            'type' => 'patients',
            'mapping' => ['first_name' => 'Name'],
        ])->assertNotFound();
    }

    public function test_system_key_header_can_register_a_tenant_from_the_central_domain(): void
    {
        config([
            'tenancy.database.template_tenant_connection' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
        ]);
        $this->withHeaders(['X-App-Key' => 'tenant-registration-test-key']);

        $response = $this->postJson('http://localhost/api/admin/tenants', [
            'domain' => 'new-clinic.test',
            'display_name' => 'New Clinic',
            'database' => 'new_clinic_db',
            'host' => 'tenant-db.test',
            'port' => 3307,
            'username' => 'tenant_user',
            'password' => 'database-secret',
            'sms_client_id' => 'sms-secret',
            'email_reply_to' => 'reply@new-clinic.test',
            'brand_color' => '#123456',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.domain', 'new-clinic.test')
            ->assertJsonPath('data.display_name', 'New Clinic')
            ->assertJsonPath('data.status', 'provisioning')
            ->assertJsonPath('data.migration_status', 'pending')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.sms_client_id');

        $tenantId = $response->json('data.id');
        $tenant = Tenant::query()->findOrFail($tenantId);
        $storedTenant = DB::connection('tenant_registry')
            ->table('tenants')
            ->where('id', $tenantId)
            ->first();

        $this->assertSame('new-clinic.test', $tenant->domains()->value('domain'));
        $this->assertSame('tenant-db.test', $tenant->tenancy_db_host);
        $this->assertSame('database-secret', $tenant->tenancy_db_password);
        $this->assertSame('sms-secret', $tenant->sms_client_id);
        $this->assertNotSame('database-secret', $storedTenant->tenancy_db_password);
        $this->assertNotSame('sms-secret', $storedTenant->sms_client_id);

        $this->postJson('http://localhost/api/admin/tenants', [
            'domain' => 'new-clinic.test',
            'display_name' => 'Duplicate Clinic',
            'database' => 'another_clinic_db',
            'username' => 'tenant_user',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('domain');
    }

    public function test_tenant_registration_requires_the_key_header_and_rejects_central_domains(): void
    {
        $this->postJson('http://localhost/api/admin/tenants', [
            'domain' => 'new-clinic.test',
            'display_name' => 'New Clinic',
            'database' => 'new_clinic_db',
        ])->assertUnauthorized();

        $this->postJson('http://localhost/api/admin/tenants', [
            'app_key' => 'tenant-registration-test-key',
            'domain' => 'new-clinic.test',
            'display_name' => 'New Clinic',
            'database' => 'new_clinic_db',
        ])->assertUnauthorized();

        $this->withHeaders(['X-App-Key' => 'wrong-key'])
            ->postJson('http://localhost/api/admin/tenants', [
                'domain' => 'new-clinic.test',
                'display_name' => 'New Clinic',
                'database' => 'new_clinic_db',
            ])->assertUnauthorized();

        $this->withHeaders(['X-App-Key' => 'tenant-registration-test-key'])
            ->postJson('http://localhost/api/admin/tenants', [
                'domain' => 'localhost',
                'display_name' => 'Central Application',
                'database' => 'central_db',
                'username' => 'tenant_user',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('domain');
    }

    public function test_tenant_users_cannot_register_other_tenants(): void
    {
        $this->createTenant('clinic-one.test');

        $this->withHeaders(['X-App-Key' => 'tenant-registration-test-key'])
            ->postJson('http://clinic-one.test/api/admin/tenants', [
                'domain' => 'new-clinic.test',
                'display_name' => 'New Clinic',
                'database' => 'new_clinic_db',
            ])->assertForbidden();
    }

    public function test_one_migration_path_is_applied_to_each_registered_tenant(): void
    {
        $first = $this->createTenant('first.test', 'provisioning');
        $second = $this->createTenant('second.test', 'provisioning');

        $this->assertSame('sqlite', config('database.connections.tenant_registry.driver'));

        config([
            'tenancy.migration_parameters' => [
                '--database' => 'tenant',
                '--force' => true,
                '--path' => [base_path('tests/Fixtures/tenant-migrations')],
                '--realpath' => true,
            ],
        ]);

        $exitCode = Artisan::call('tenants:migrate');

        $this->assertSame(0, $exitCode, Artisan::output());

        foreach ([$first, $second] as $tenant) {
            $hasMigration = app(TenantContext::class)->run(
                $tenant,
                fn (): bool => Schema::hasTable('tenant_migration_probes')
            );

            $this->assertTrue($hasMigration);
            $this->assertSame('active', $tenant->fresh()->status);
            $this->assertSame('succeeded', $tenant->fresh()->migration_status);
        }
    }

    private function createTenant(string $domain, string $status = 'active'): Tenant
    {
        $databaseName = 'tenant_test_'.Str::uuid().'.sqlite';
        $databaseFile = database_path($databaseName);
        $this->tenantDatabaseFiles[] = $databaseFile;
        file_put_contents($databaseFile, '');

        $tenant = Tenant::query()->create([
            'display_name' => ucfirst(str_replace('.test', '', $domain)),
            'status' => $status,
            'sms_client_id' => 'sms-secret',
            'tenancy_db_name' => $databaseName,
            'tenancy_db_host' => 'localhost',
            'tenancy_db_connection' => config('tenancy.database.template_tenant_connection'),
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }
}
