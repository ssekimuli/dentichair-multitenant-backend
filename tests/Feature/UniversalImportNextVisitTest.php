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

class UniversalImportNextVisitTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $branch = Branch::create([
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
            'branch_id' => $branch->id,
            'salary' => 8000.00,
            'hire_date' => '2024-01-01',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);
    }

    private function importPatients(string $csv): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('next_visit.csv', $csv);

        return $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'first_name',
                    'PHONE' => 'phone',
                    'LAST VISIT' => 'last_visit',
                    'NEXT VISIT' => 'next_visit',
                ],
            ]);
    }

    /** @test */
    public function it_creates_an_appointment_for_a_future_next_visit()
    {
        $last = now()->subDays(10)->toDateString();
        $next = now()->addDays(30)->toDateString();

        $csv = "NAME,PHONE,LAST VISIT,NEXT VISIT\n";
        $csv .= "\"Amos\",\"0700111222\",\"{$last}\",\"{$next}\"\n";

        $this->importPatients($csv)
            ->assertStatus(200)
            ->assertJson(['data' => ['imported' => 1, 'failed' => 0]]);

        $patient = Patient::where('first_name', 'Amos')->firstOrFail();
        $this->assertEquals($next, $patient->next_visit->toDateString());
        $this->assertEquals($last, $patient->last_visit->toDateString());

        $appointment = Appointment::where('patient_id', $patient->id)->first();
        $this->assertNotNull($appointment);
        $this->assertEquals($next, $appointment->date->toDateString());
        $this->assertEquals('Scheduled', $appointment->status);
    }

    /** @test */
    public function it_does_not_create_an_appointment_for_a_past_next_visit()
    {
        $csv = "NAME,PHONE,NEXT VISIT\n";
        $csv .= "\"Brenda\",\"0700111333\",\"" . now()->subDays(5)->toDateString() . "\"\n";

        $this->importPatients($csv)
            ->assertJson(['data' => ['imported' => 1, 'failed' => 0]]);

        $this->assertDatabaseCount('appointments', 0);
    }

    /** @test */
    public function it_does_not_duplicate_an_appointment_already_on_that_date()
    {
        $next = now()->addDays(20)->toDateString();

        $csv = "NAME,PHONE,NEXT VISIT\n";
        $csv .= "\"Caleb\",\"0700111444\",\"{$next}\"\n";

        // Import twice: the second run updates the patient and must not add another appointment
        $this->importPatients($csv)->assertJson(['data' => ['imported' => 1, 'failed' => 0]]);
        $this->importPatients($csv);

        $patient = Patient::where('first_name', 'Caleb')->firstOrFail();
        $this->assertEquals(1, Appointment::where('patient_id', $patient->id)->count());
    }
}
