<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;
    private RolePermission $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = RolePermission::create([
            'id' => 1,
            'name' => 'Dentist',
            'permissions' => []
        ]);

        $this->branch = Branch::create([
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'KES'
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => $this->role->id,
            'role' => $this->role->name,
            'branch_id' => $this->branch->id
        ]);
    }

    /** @test */
    public function it_can_add_soft_tissue_record()
    {
        $patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '2000-01-15',
            'gender' => 'Male',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->employee)->postJson("/api/patients/{$patient->id}/soft-tissue-records", [
            'location' => 'Right buccal',
            'type' => 'Lesion',
            'description' => 'na'
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('soft_tissue_records', [
            'patient_id' => $patient->id,
            'location' => 'Right buccal',
            'type' => 'Lesion',
            'description' => 'na'
        ]);
    }

    /** @test */
    public function it_can_add_orthodontic_record()
    {
        $patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '2000-01-15',
            'gender' => 'Male',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->employee)->postJson("/api/patients/{$patient->id}/orthodontic-records", [
            'appliance' => 'Braces',
            'wire_size' => '.016 Nickel Titanium',
            'bracket_changes' => ['14', '24'],
            'notes' => 'Good progress'
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orthodontic_records', [
            'patient_id' => $patient->id,
            'appliance' => 'Braces',
            'wire_size' => '.016 Nickel Titanium',
            'notes' => 'Good progress'
        ]);
    }
}
