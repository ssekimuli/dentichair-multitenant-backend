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

class UniversalImportAppointmentsTest extends TestCase
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
    }

    /** @test */
    public function it_maps_phone_header_to_contact_for_appointments()
    {
        $csv = "NAME,PHONE,DATE,TIME,TREATMENT\n";
        $csv .= "Andrew,+256700111222,2026-08-15,10:00,Cleaning\n";

        $file = UploadedFile::fake()->createWithContent('appointments_map.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', [
                'file' => $file,
                'table' => 'appointments',
            ]);

        $response->assertStatus(200);
        $mapping = $response->json('data.mapping');

        $this->assertEquals('contact', $mapping['PHONE']);
        $this->assertEquals('patient_name', $mapping['NAME']);
        $this->assertEquals('date', $mapping['DATE']);
        $this->assertEquals('treatment', $mapping['TREATMENT']);
        $this->assertEmpty($response->json('data.missing_required_fields'));
    }

    /** @test */
    public function it_links_appointment_to_patient_when_phone_matches()
    {
        $patient = Patient::create([
            'first_name' => 'Andrew',
            'last_name' => 'Mukasa',
            'phone' => '+256700111222',
            'branch_id' => $this->branch->id,
            'gender' => 'Male',
            'date_of_birth' => '1995-05-10',
            'status' => 'Active',
        ]);

        $csv = "NAME,PHONE,DATE,TIME,TREATMENT\n";
        $csv .= "Andrew,+256700111222,2026-08-15,10:00,Cleaning\n";

        $file = UploadedFile::fake()->createWithContent('appointments_matched.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'appointments',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'patient_name',
                    'PHONE' => 'contact',
                    'DATE' => 'date',
                    'TIME' => 'time',
                    'TREATMENT' => 'treatment',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        $appointment = Appointment::first();
        $this->assertNotNull($appointment);
        $this->assertEquals($patient->id, $appointment->patient_id);
        $this->assertEquals('Andrew', $appointment->patient_name);
    }

    /** @test */
    public function it_creates_patient_when_phone_matches_no_patient()
    {
        $csv = "NAME,PHONE,DATE,TIME,TREATMENT\n";
        $csv .= "Stranger Person,+256799999999,2026-08-15,10:00,Cleaning\n";

        $file = UploadedFile::fake()->createWithContent('appointments_unmatched.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'appointments',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'patient_name',
                    'PHONE' => 'contact',
                    'DATE' => 'date',
                    'TIME' => 'time',
                    'TREATMENT' => 'treatment',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        $patient = \App\Models\Patient::where('phone', '0799999999')->first();
        $this->assertNotNull($patient);
        $this->assertEquals('Stranger', $patient->first_name);
        $this->assertEquals(1, \App\Models\Appointment::where('patient_id', $patient->id)->count());
    }
}
