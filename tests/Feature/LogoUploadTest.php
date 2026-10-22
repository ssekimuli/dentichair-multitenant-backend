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

    #[Test]
    public function it_renders_invoice_print_view_with_logo_and_watermark()
    {
        $setting = GlobalSetting::getSettings();
        $setting->clinic_name = 'Dental Excellence Clinic';
        $setting->logo = 'https://cdn.example.com/clinic-logo.png';
        $setting->save();

        $patient = \App\Models\Patient::create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@example.com',
            'date_of_birth' => '1985-05-12',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);

        $invoice = \App\Models\Invoice::create([
            'patient_id' => $patient->id,
            'patient_name' => 'Sarah Connor',
            'branch_id' => $this->branch->id,
            'amount' => 250000.00,
            'paid_amount' => 150000.00,
            'status' => 'Pending',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'payment_method' => 'Insurance',
            'category' => 'General Dentistry',
        ]);

        \App\Models\InvoiceTreatment::create([
            'invoice_id' => $invoice->id,
            'procedure' => 'Root Canal Therapy #16',
            'cost' => 250000.00,
            'quantity' => 1,
        ]);

        $response = $this->get("/invoices/{$invoice->id}/print");

        $response->assertStatus(200);
        $response->assertSee('TAX INVOICE');
        $response->assertSee('Dental Excellence Clinic');
        $response->assertSee('https://cdn.example.com/clinic-logo.png');
        $response->assertSee('print-watermark');
        $response->assertSee('print-watermark-logo');
        $response->assertSee('Root Canal Therapy #16');
        $response->assertSee('250,000');
        $response->assertSee('150,000');
        $response->assertSee('100,000'); // Balance due
    }

    #[Test]
    public function it_renders_medical_history_print_view_with_next_appointment_date()
    {
        $patient = \App\Models\Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-15',
            'branch_id' => $this->branch->id,
            'next_visit' => '2026-11-25',
            'status' => 'Active',
        ]);

        $record = \App\Models\MedicalHistory::create([
            'patient_id' => $patient->id,
            'date' => now()->toDateString(),
            'chief_complaint' => 'Tooth ache on upper left molar',
            'diagnosis' => 'Irreversible pulpitis #26',
            'treatment_plan' => 'Root canal treatment and porcelain crown',
            'treatment_given' => 'Pulpectomy and sedative dressing',
            'doctor_name' => 'Alice Walker',
        ]);

        $response = $this->get("/medical-histories/{$record->id}/print");

        $response->assertStatus(200);
        $response->assertSee('Next Appointment Date:');
        $response->assertSee('Nov 25, 2026');
        $response->assertSee('Dental Officer / Dental Surgeon Signature & Stamp:', false);
        $response->assertDontSee('Dr. Alice Walker');
    }

    #[Test]
    public function it_updates_patient_next_visit_when_editing_medical_history()
    {
        $patient = \App\Models\Patient::create([
            'first_name' => 'Michael',
            'last_name' => 'Scott',
            'email' => 'michael.scott@dunder.com',
            'date_of_birth' => '1975-03-15',
            'branch_id' => $this->branch->id,
            'next_visit' => '2026-11-01',
            'history' => 'No known allergies',
            'status' => 'Active',
        ]);

        $record = \App\Models\MedicalHistory::create([
            'patient_id' => $patient->id,
            'date' => '2026-10-22',
            'chief_complaint' => 'Routine scaling and checkup',
            'diagnosis' => 'Generalized plaque-induced gingivitis',
            'treatment_plan' => 'Prophylaxis and fluoride varnish',
            'treatment_given' => 'Full mouth ultrasonic scaling',
            'doctor_name' => 'Dr. C. Foreman',
        ]);

        // Edit medical history with new next_visit date and updated medical history notes
        $response = $this->actingAs($this->employee)->putJson("/api/medical-histories/{$record->id}", [
            'chief_complaint' => 'Routine scaling and checkup - Followup',
            'next_visit' => '2026-12-15',
            'medical_history' => 'Penicillin allergy noted on review',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $record->id,
                    'patient' => [
                        'id' => $patient->id,
                        'next_visit' => '2026-12-15T00:00:00.000000Z',
                    ]
                ]
            ]);

        $patient->refresh();
        $this->assertEquals('2026-12-15', $patient->next_visit->format('Y-m-d'));
        $this->assertEquals('Penicillin allergy noted on review', $patient->history);
    }
}
