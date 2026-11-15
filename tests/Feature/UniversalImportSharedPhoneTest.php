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

class UniversalImportSharedPhoneTest extends TestCase
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

    private function importPatients(string $csv)
    {
        $file = UploadedFile::fake()->createWithContent('family.csv', $csv);

        return $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'first_name',
                    'LAST' => 'last_name',
                    'PHONE' => 'phone',
                ],
            ]);
    }

    /** @test */
    public function it_keeps_relatives_who_share_a_phone_number_as_separate_patients()
    {
        $csv = "NAME,LAST,PHONE\n";
        $csv .= "\"Brenda\",\"Nakato\",\"0700111555\"\n";
        $csv .= "\"Samiiro\",\"Dahir\",\"0700111555\"\n";
        $csv .= "\"Amos\",\"Nakato\",\"0700111555\"\n";

        $this->importPatients($csv)->assertJson(['data' => ['imported' => 3, 'failed' => 0]]);

        $this->assertDatabaseCount('patients', 3);
    }

    /** @test */
    public function it_does_not_duplicate_family_members_when_the_same_file_is_imported_again()
    {
        $csv = "NAME,LAST,PHONE\n";
        $csv .= "\"Brenda\",\"Nakato\",\"0700111555\"\n";
        $csv .= "\"Samiiro\",\"Dahir\",\"0700111555\"\n";

        $this->importPatients($csv);
        $this->importPatients($csv);

        $this->assertDatabaseCount('patients', 2);
    }

    /** @test */
    public function it_links_an_appointment_to_the_named_relative_who_shares_the_phone()
    {
        Patient::create([
            'first_name' => 'Brenda', 'last_name' => 'Nakato', 'phone' => '0700111555',
            'branch_id' => $this->branch->id, 'gender' => 'Female',
            'date_of_birth' => '1980-03-04', 'status' => 'Active',
        ]);
        $samiiro = Patient::create([
            'first_name' => 'Samiiro', 'last_name' => 'Dahir', 'phone' => '0700111555',
            'branch_id' => $this->branch->id, 'gender' => 'Female',
            'date_of_birth' => '2015-01-01', 'status' => 'Active',
        ]);

        $csv = "Date,Patient,Service,Contact,Time\n";
        $csv .= "11/09/2026,\"SAMIIRO DAHIR\",Follow-up,0700111555,10:00\n";

        $file = UploadedFile::fake()->createWithContent('appt_family.csv', $csv);

        $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'appointments',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'Date' => 'date',
                    'Patient' => 'patient_name',
                    'Service' => 'treatment',
                    'Contact' => 'contact',
                    'Time' => 'time',
                ],
            ])
            ->assertJson(['data' => ['imported' => 1, 'failed' => 0]]);

        $this->assertEquals($samiiro->id, Appointment::firstOrFail()->patient_id);
    }
}
