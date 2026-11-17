<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\MedicalHistory;
use App\Models\Patient;
use App\Models\RestorativeRecord;
use App\Models\RolePermission;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestorativeMarkingTest extends TestCase
{
    use RefreshDatabase;

    private function setUpPatientAndDoctor(): array
    {
        $branch = Branch::create(['name' => 'Main', 'address' => 'x']);
        $patient = Patient::create([
            'first_name' => 'Frank', 'last_name' => 'Dan', 'email' => 'f@example.com',
            'date_of_birth' => '1990-01-01', 'branch_id' => $branch->id, 'status' => 'Active',
        ]);
        $role = RolePermission::create(['name' => 'Doctor', 'permissions' => []]);
        $doctor = Employee::factory()->create([
            'role' => 'Doctor', 'role_id' => $role->id, 'branch_id' => $branch->id,
        ]);

        return [$patient, $doctor];
    }

    /** @test */
    public function chart_markings_do_not_create_treatment_plans_or_invoices(): void
    {
        [$patient, $doctor] = $this->setUpPatientAndDoctor();

        $response = $this->actingAs($doctor)->postJson("/api/patients/{$patient->id}/restorative-records/batch", [
            'records' => [
                ['tooth_id' => 16, 'surface' => 'DM', 'material' => 'Filling Surface Marking', 'notes' => 'Location view marked'],
                // Even with costs sent, a marking must not bill.
                ['tooth_id' => 11, 'surface' => 'F', 'material' => 'Composite', 'procedure_cost' => 200, 'material_cost' => 50],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('restorative_records', 2);
        $this->assertDatabaseCount('treatment_plans', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    /** @test */
    public function chart_markings_are_written_to_medical_history(): void
    {
        [$patient, $doctor] = $this->setUpPatientAndDoctor();

        $payload = ['records' => [
            ['tooth_id' => 16, 'surface' => 'DM', 'material' => 'Filling Surface Marking', 'notes' => 'Location view marked'],
        ]];

        $this->actingAs($doctor)->postJson("/api/patients/{$patient->id}/restorative-records/batch", $payload)->assertStatus(201);
        // Sending the same marking again must not duplicate the line.
        $this->actingAs($doctor)->postJson("/api/patients/{$patient->id}/restorative-records/batch", $payload)->assertStatus(201);

        $history = MedicalHistory::where('patient_id', $patient->id)->whereDate('date', now()->toDateString())->first();
        $this->assertNotNull($history);
        $this->assertEquals(
            '- Tooth #16 (DM): Filling Surface Marking: Location view marked',
            $history->intra_oral_exam
        );
        $this->assertDatabaseCount('treatment_plans', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    /** @test */
    public function single_restorative_record_still_creates_treatment_plan(): void
    {
        // The single-record endpoint is unchanged; only the batch marking path opts out.
        [$patient, $doctor] = $this->setUpPatientAndDoctor();
        $this->actingAs($doctor);

        $record = $patient->restorativeRecords()->create([
            'tooth_id' => 16, 'surface' => 'M', 'material' => 'Composite',
            'procedure_cost' => 200, 'material_cost' => 0, 'other_charges' => 0,
        ]);

        $this->assertInstanceOf(RestorativeRecord::class, $record);
        $this->assertDatabaseCount('treatment_plans', 1);
        $this->assertEquals(1, TreatmentPlan::count());
        $this->assertEquals(0, Invoice::count());
    }
}
