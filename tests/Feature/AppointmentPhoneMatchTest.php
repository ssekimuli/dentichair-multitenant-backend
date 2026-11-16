<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AppointmentPhoneMatchTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main Dental Clinic', 'address' => 'Kampala Road']);

        RolePermission::create(['id' => 1, 'name' => 'Admin', 'permissions' => ['manage_patients']]);

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

    private function makePatient(string $first, string $last, string $phone): Patient
    {
        return Patient::create([
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
            'branch_id' => $this->branch->id,
            'gender' => 'Male',
            'date_of_birth' => '1990-01-01',
            'status' => 'Active',
        ]);
    }

    /** @return array{imported: int, failed: int} */
    private function importRows(array $rows): array
    {
        $csv = "NAME,PHONE,DATE,TIME,TREATMENT\n";
        // A different date per row: the same patient on the same day counts as one appointment
        foreach ($rows as $i => [$name, $phone]) {
            $date = now()->addDays(10 + $i)->format('Y-m-d');
            $csv .= "\"{$name}\",\"{$phone}\",{$date},10:00,Cleaning\n";
        }

        $file = UploadedFile::fake()->createWithContent('appointments.csv', $csv);

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

        $response->assertStatus(200);

        return ['imported' => $response->json('data.imported'), 'failed' => $response->json('data.failed')];
    }

    /** @test */
    public function international_phone_links_to_patient_stored_with_leading_zero_even_when_name_differs()
    {
        $patient = $this->makePatient('Ikhlas', 'Ali', '0700111222');

        $result = $this->importRows([
            ['MS IKHLAS', '+256700111222'],
            ['Someone Else', '+256 700 111 222'],
        ]);

        $this->assertEquals(['imported' => 2, 'failed' => 0], $result);
        $this->assertEquals([$patient->id, $patient->id], Appointment::orderBy('id')->pluck('patient_id')->all());
    }

    /** @test */
    public function shared_phone_is_resolved_by_name()
    {
        $ikhlas = $this->makePatient('Ikhlas', 'Ali', '0700111222');
        $brian = $this->makePatient('Brian', 'Ochieng', '0700111222');

        $result = $this->importRows([
            ['Brian Ochieng', '+256700111222'],
        ]);

        $this->assertEquals(['imported' => 1, 'failed' => 0], $result);
        $this->assertEquals($brian->id, Appointment::first()->patient_id);
        $this->assertNotEquals($ikhlas->id, Appointment::first()->patient_id);
    }
}
