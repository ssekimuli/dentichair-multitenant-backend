<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Employee;
use App\Models\GlobalSetting;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LogoUploadTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        RolePermission::firstOrCreate(
            ['id' => 1],
            ['name' => 'Super Admin', 'permissions' => ['*']]
        );

        $this->branch = Branch::create([
            'name' => 'Main Clinic Branch',
            'address' => 'Plot 42 Medical Center Road',
            'currency' => 'UGX'
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => 1,
            'branch_id' => $this->branch->id
        ]);
    }

    #[Test]
    public function it_can_upload_logo_using_field_logo()
    {
        $file = UploadedFile::fake()->image('clinic_logo.png', 600, 600);

        $response = $this->postJson('/api/settings/logo', [
            'logo' => $file
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logo image uploaded successfully.'
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'logo',
                'logo_url',
                'data' => [
                    'logo',
                    'logo_url',
                    'clinic_name'
                ]
            ]);

        $logoUrl = $response->json('logo');
        $this->assertNotEmpty($logoUrl);

        $setting = GlobalSetting::getSettings();
        $this->assertEquals($logoUrl, $setting->logo);
    }

    #[Test]
    public function it_can_upload_logo_using_alternate_field_names()
    {
        // 1. Using field 'image'
        $file1 = UploadedFile::fake()->image('image_field.jpg', 400, 400);
        $res1 = $this->postJson('/api/settings/logo', [
            'image' => $file1
        ]);
        $res1->assertStatus(200)->assertJson(['success' => true]);

        // 2. Using field 'file' and alternate route /api/upload/logo
        $file2 = UploadedFile::fake()->image('file_field.webp', 400, 400);
        $res2 = $this->postJson('/api/upload/logo', [
            'file' => $file2
        ]);
        $res2->assertStatus(200)->assertJson(['success' => true]);
    }

    #[Test]
    public function it_rejects_non_image_files()
    {
        $fakePdf = UploadedFile::fake()->create('document.pdf', 200, 'application/pdf');

        $response = $this->postJson('/api/settings/logo', [
            'logo' => $fakePdf
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_error_if_no_file_provided()
    {
        $response = $this->postJson('/api/settings/logo', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false
            ]);
    }

    #[Test]
    public function it_can_get_current_clinic_logo()
    {
        $setting = GlobalSetting::getSettings();
        $setting->logo = 'https://example.com/storage/logos/test-logo.png';
        $setting->save();

        $response = $this->getJson('/api/settings/logo');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'logo' => 'https://example.com/storage/logos/test-logo.png',
                'logo_url' => 'https://example.com/storage/logos/test-logo.png'
            ]);
    }

    #[Test]
    public function receipt_settings_returns_active_logo()
    {
        $setting = GlobalSetting::getSettings();
        $setting->logo = 'https://example.com/storage/logos/receipt-logo.png';
        $setting->save();

        $response = $this->getJson('/api/settings/receipt');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'logo' => 'https://example.com/storage/logos/receipt-logo.png',
                'logo_url' => 'https://example.com/storage/logos/receipt-logo.png'
            ]);
    }

    #[Test]
    public function it_can_upload_branch_specific_logo()
    {
        $file = UploadedFile::fake()->image('branch_logo.png', 500, 500);

        $response = $this->actingAs($this->employee)
            ->postJson("/api/branches/{$this->branch->id}/logo", [
                'logo' => $file
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Branch logo uploaded successfully.',
                'data' => [
                    'branch_id' => $this->branch->id
                ]
            ]);

        $this->branch->refresh();
        $this->assertNotNull($this->branch->logo);

        // Fetch branch logo
        $getResponse = $this->actingAs($this->employee)
            ->getJson("/api/branches/{$this->branch->id}/logo");

        $getResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'branch_id' => $this->branch->id,
                'logo' => $this->branch->logo
            ]);
    }

    #[Test]
    public function it_can_delete_clinic_logo()
    {
        $setting = GlobalSetting::getSettings();
        $setting->logo = 'https://example.com/logos/to-delete.png';
        $setting->save();

        $response = $this->deleteJson('/api/settings/logo');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logo removed successfully.',
                'logo' => null
            ]);

        $setting->refresh();
        $this->assertNull($setting->logo);
    }
}
