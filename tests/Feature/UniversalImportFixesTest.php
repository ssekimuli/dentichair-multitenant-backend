<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ImportRowError;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalImportFixesTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->branch = Branch::create(['name' => 'Main Dental Clinic', 'address' => 'Kampala Road']);

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

    private function execute(string $table, string $csv, array $mapping, string $mode = 'insert')
    {
        $file = UploadedFile::fake()->createWithContent("{$table}.csv", $csv);

        return $this->actingAs($this->employee)->postJson('/api/import/execute', [
            'file' => $file,
            'table' => $table,
            'mode' => $mode,
            'async' => false,
            'mapping' => $mapping,
        ]);
    }

    /** @test */
    public function a_row_with_no_name_updates_the_known_patient_instead_of_creating_an_imported_one()
    {
        Patient::create([
            'first_name' => 'Brenda', 'last_name' => 'Nakato', 'phone' => '0700111555',
            'branch_id' => $this->branch->id, 'gender' => 'Female',
            'date_of_birth' => '1980-03-04', 'status' => 'Active',
        ]);

        $csv = "NAME,PHONE\n\"\",\"0700111555\"\n";

        $this->execute('patients', $csv, ['NAME' => 'first_name', 'PHONE' => 'phone'])
            ->assertJson(['data' => ['failed' => 0]]);

        $this->assertDatabaseCount('patients', 1);
        $this->assertEquals('Brenda', Patient::firstOrFail()->first_name);
    }

    /** @test */
    public function an_appointment_is_flagged_when_two_patients_match_the_phone_and_name()
    {
        Patient::create([
            'first_name' => 'Amos', 'last_name' => 'Nakato', 'phone' => '0700111777',
            'branch_id' => $this->branch->id, 'gender' => 'Male', 'date_of_birth' => '2010-01-01', 'status' => 'Active',
        ]);
        Patient::create([
            'first_name' => 'Amos', 'last_name' => 'Nakato', 'phone' => '0700111777',
            'branch_id' => $this->branch->id, 'gender' => 'Male', 'date_of_birth' => '2012-01-01', 'status' => 'Active',
        ]);

        $csv = "Date,Patient,Service,Contact,Time\n";
        $csv .= "11/09/2026,\"AMOS NAKATO\",Follow-up,0700111777,10:00\n";

        $this->execute('appointments', $csv, [
            'Date' => 'date', 'Patient' => 'patient_name', 'Service' => 'treatment',
            'Contact' => 'contact', 'Time' => 'time',
        ])->assertJson(['data' => ['imported' => 0, 'failed' => 1]]);

        $this->assertDatabaseCount('appointments', 0);
        $this->assertTrue(ImportRowError::where('error_code', 'PATIENT_AMBIGUOUS')->exists());
    }

    /** @test */
    public function a_medical_history_is_flagged_when_two_patients_match_the_phone_and_name()
    {
        Patient::create([
            'first_name' => 'Amos', 'last_name' => 'Nakato', 'phone' => '0700111888',
            'branch_id' => $this->branch->id, 'gender' => 'Male', 'date_of_birth' => '2010-01-01', 'status' => 'Active',
        ]);
        Patient::create([
            'first_name' => 'Amos', 'last_name' => 'Nakato', 'phone' => '0700111888',
            'branch_id' => $this->branch->id, 'gender' => 'Male', 'date_of_birth' => '2012-01-01', 'status' => 'Active',
        ]);

        $csv = "NAME,CONTACT,CHIEF COMPLAINT\n";
        $csv .= "\"Amos Nakato\",\"0700111888\",\"Toothache\"\n";

        $this->execute('medical_histories', $csv, [
            'NAME' => 'patient_name', 'CONTACT' => 'contact', 'CHIEF COMPLAINT' => 'chief_complaint',
        ])->assertJson(['data' => ['imported' => 0, 'failed' => 1]]);

        $this->assertDatabaseCount('medical_histories', 0);
        $this->assertTrue(ImportRowError::where('error_code', 'PATIENT_AMBIGUOUS')->exists());
    }
}
