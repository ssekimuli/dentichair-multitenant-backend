<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\MedicalHistory;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PatientProcedureHistoryTest extends TestCase
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

    /** @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows name, phone, visit date, procedure */
    private function importPatients(array $rows, bool $withProcedureColumn = true): void
    {
        $csv = $withProcedureColumn
            ? "NAME,PHONE,VISIT DATE,PROCEDURE\n"
            : "NAME,PHONE,VISIT DATE\n";
        foreach ($rows as [$name, $phone, $date, $procedure]) {
            $csv .= $withProcedureColumn
                ? "\"{$name}\",\"{$phone}\",{$date},\"{$procedure}\"\n"
                : "\"{$name}\",\"{$phone}\",{$date}\n";
        }

        $mapping = [
            'NAME' => 'first_name',
            'PHONE' => 'phone',
            'VISIT DATE' => 'last_visit',
        ];
        if ($withProcedureColumn) {
            $mapping['PROCEDURE'] = 'history';
        }

        $file = UploadedFile::fake()->createWithContent('patients_procedures.csv', $csv);

        $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => $mapping,
            ])
            ->assertStatus(200);
    }

    /** @test */
    public function importing_a_patient_with_a_procedure_creates_a_medical_history()
    {
        $this->importPatients([['Amina Nakato', '0772555201', '2026-08-15', 'Scaling and polishing']]);

        $patient = Patient::where('phone', '0772555201')->firstOrFail();
        $history = MedicalHistory::where('patient_id', $patient->id)->get();

        $this->assertCount(1, $history);
        $this->assertEquals('Scaling and polishing', $history->first()->treatment_given);
        $this->assertEquals('2026-08-15', $history->first()->date->toDateString());
        $this->assertTrue((bool) $history->first()->consent);
    }

    /** @test */
    public function importing_a_patient_without_a_procedure_creates_no_medical_history()
    {
        $this->importPatients([['Brian Ochieng', '0701999333', '2026-08-15', '']]);

        $patient = Patient::where('phone', '0701999333')->firstOrFail();
        $this->assertEquals(0, MedicalHistory::where('patient_id', $patient->id)->count());
    }

    /** @test */
    public function re_importing_the_same_procedure_does_not_duplicate_the_medical_history()
    {
        $row = [['Amina Nakato', '0772555202', '2026-08-15', 'Filling']];
        $this->importPatients($row);
        $this->importPatients($row);

        $patient = Patient::where('phone', '0772555202')->firstOrFail();
        $this->assertEquals(1, MedicalHistory::where('patient_id', $patient->id)->count());
    }

    /** @test */
    public function a_different_procedure_on_another_date_is_recorded_separately()
    {
        $this->importPatients([['Amina Nakato', '0772555203', '2026-08-15', 'Filling']]);
        $this->importPatients([['Amina Nakato', '0772555203', '2026-09-20', 'Extraction']]);

        $patient = Patient::where('phone', '0772555203')->firstOrFail();
        $this->assertEquals(2, MedicalHistory::where('patient_id', $patient->id)->count());
    }
}
