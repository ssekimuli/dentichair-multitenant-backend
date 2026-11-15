<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalImportAppointmentsSheetTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->branch = Branch::create([
            'name' => 'Main Dental Clinic',
            'address' => 'Kampala Road',
        ]);

        RolePermission::create([
            'id' => 1,
            'name' => 'Admin',
            'permissions' => ['manage_patients'],
        ]);

        $this->employee = Employee::create([
            'name' => 'Dr. Admin',
            'email' => 'admin@dentistchair.com',
            'role' => 'Admin',
            'role_id' => 1,
            'branch_id' => $this->branch->id,
            'salary' => 8000.00,
            'hire_date' => '2024-01-01',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);

        Patient::create([
            'first_name' => 'Allen', 'last_name' => 'Tendo', 'phone' => '0751851162',
            'branch_id' => $this->branch->id, 'gender' => 'Male',
            'date_of_birth' => '1990-01-01', 'status' => 'Active',
        ]);
        Patient::create([
            'first_name' => 'Samiiro', 'last_name' => 'Dahir', 'phone' => '0703532369',
            'branch_id' => $this->branch->id, 'gender' => 'Female',
            'date_of_birth' => '1990-01-01', 'status' => 'Active',
        ]);
    }

    private function sheet(): string
    {
        return "Date,Heure,Patient Number,Patient,Service,Contact,Observation\n"
            . "11/09/2026,\"10:00 (30min)\",,ALLEN TENDO,Follow-up Consultation,+256751851162,\n"
            . "11/09/2026,\"12:00 (30min)\",,SAMIIRO DAHIR,Follow-up Consultation,+256703532369,\n";
    }

    /** @test */
    public function it_auto_maps_the_appointment_sheet_headers()
    {
        $file = UploadedFile::fake()->createWithContent('appointments_sheet.csv', $this->sheet());

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', ['file' => $file, 'table' => 'appointments']);

        $response->assertStatus(200);
        $mapping = $response->json('data.mapping');

        $this->assertEquals('date', $mapping['Date']);
        $this->assertEquals('time', $mapping['Heure']);
        $this->assertEquals('patient_name', $mapping['Patient']);
        $this->assertEquals('treatment', $mapping['Service']);
        $this->assertEquals('contact', $mapping['Contact']);
    }

    /** @test */
    public function it_imports_the_sheet_as_linked_appointments()
    {
        $file = UploadedFile::fake()->createWithContent('appointments_sheet.csv', $this->sheet());

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'appointments',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'Date' => 'date',
                    'Heure' => 'time',
                    'Patient' => 'patient_name',
                    'Service' => 'treatment',
                    'Contact' => 'contact',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['imported' => 2, 'failed' => 0]]);

        $allen = Appointment::where('patient_name', 'ALLEN TENDO')->firstOrFail();
        $this->assertEquals(Patient::where('first_name', 'Allen')->value('id'), $allen->patient_id);
        $this->assertEquals('2026-09-11', $allen->date->toDateString());
        $this->assertEquals('10:00 AM', $allen->time);
        $this->assertEquals('Follow-up Consultation', $allen->treatment);

        $samiiro = Appointment::where('patient_name', 'SAMIIRO DAHIR')->firstOrFail();
        $this->assertEquals(Patient::where('first_name', 'Samiiro')->value('id'), $samiiro->patient_id);
        $this->assertEquals('12:00 PM', $samiiro->time);
    }
}
