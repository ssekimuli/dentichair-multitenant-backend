<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientWithoutContactTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = RolePermission::firstOrCreate(['id' => 1], [
            'name' => 'Dentist',
            'permissions' => []
        ]);

        $this->branch = Branch::firstOrCreate(['id' => 1], [
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'KES'
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => $role->id,
            'role' => $role->name,
            'branch_id' => $this->branch->id
        ]);
    }

    public function test_api_can_create_patient_without_phone_or_email(): void
    {
        $response = $this->actingAs($this->employee)->postJson('/api/patients', [
            'first_name' => 'No',
            'last_name' => 'Contact',
            'age' => 30,
            'branch_id' => $this->branch->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('patients', [
            'first_name' => 'No',
            'last_name' => 'Contact',
            'phone' => null,
            'email' => null,
        ]);
    }

    public function test_api_still_rejects_a_malformed_phone_when_one_is_given(): void
    {
        $response = $this->actingAs($this->employee)->postJson('/api/patients', [
            'first_name' => 'Bad',
            'last_name' => 'Phone',
            'phone' => '0700000000',
            'age' => 30,
            'branch_id' => $this->branch->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_patient_without_phone_appears_in_billing_patient_list(): void
    {
        $patient = Patient::create([
            'first_name' => 'Billed',
            'last_name' => 'Walkin',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
            'date_of_birth' => '1990-01-01',
            'age' => 36,
        ]);

        $response = $this->actingAs($this->employee)->getJson('/api/patients-list');

        $response->assertStatus(200);
        $this->assertContains($patient->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_api_can_update_patient_without_phone(): void
    {
        $patient = Patient::create([
            'first_name' => 'Edit',
            'last_name' => 'Me',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
            'date_of_birth' => '1990-01-01',
            'age' => 36,
        ]);

        $response = $this->actingAs($this->employee)->putJson("/api/patients/{$patient->id}", [
            'first_name' => 'Edit',
            'last_name' => 'Me',
            'phone' => null,
            'age' => 37,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'age' => 37, 'phone' => null]);
    }

    public function test_web_registration_accepts_patient_without_phone(): void
    {
        $response = $this->post(route('patient.register.store'), [
            'first_name' => 'Walk',
            'last_name' => 'In',
            'age' => 40,
            'branch_id' => $this->branch->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('patients', ['first_name' => 'Walk', 'last_name' => 'In', 'phone' => null]);
    }

    public function test_web_registration_does_not_treat_missing_phone_as_duplicate(): void
    {
        Patient::create([
            'first_name' => 'Existing',
            'last_name' => 'NoPhone',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
            'date_of_birth' => '1990-01-01',
            'age' => 36,
        ]);

        $response = $this->post(route('patient.register.store'), [
            'first_name' => 'Second',
            'last_name' => 'NoPhone',
            'age' => 40,
            'branch_id' => $this->branch->id,
        ]);

        $response->assertRedirect();
        $this->assertSame(2, Patient::whereNull('phone')->count());
    }
}
