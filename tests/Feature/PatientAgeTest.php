<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientAgeTest extends TestCase
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

    public function test_can_create_patient_with_age()
    {
        $payload = [
            'first_name' => 'John',
            'last_name' => 'Tester',
            'email' => 'john.tester.' . uniqid() . '@example.com',
            'phone' => '+353878686887',
            'address' => '123 Test St',
            'gender' => 'Male',
            'age' => 39,
            'date_of_birth' => '1987-09-10',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ];

        $response = $this->actingAs($this->employee)->postJson('/api/patients', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.age', 39);

        $this->assertDatabaseHas('patients', [
            'phone' => '+353878686887',
            'age' => 39,
        ]);
    }

    public function test_can_update_patient_age()
    {
        $patient = Patient::create([
            'first_name' => 'Jane',
            'last_name' => 'Tester',
            'phone' => '+353878686888',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
            'date_of_birth' => '1990-01-01',
            'age' => 36,
        ]);

        $response = $this->actingAs($this->employee)->putJson("/api/patients/{$patient->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Tester',
            'phone' => '+353878686888',
            'age' => 40,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.age', 40);

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'age' => 40,
        ]);
    }

    public function test_can_create_patient_with_only_dob_and_auto_calculate_age()
    {
        $payload = [
            'first_name' => 'Alice',
            'last_name' => 'Tester',
            'email' => 'alice.tester.' . uniqid() . '@example.com',
            'phone' => '+353878686889',
            'gender' => 'Female',
            'date_of_birth' => now()->subYears(25)->format('Y-m-d'),
            'branch_id' => $this->branch->id,
        ];

        $response = $this->actingAs($this->employee)->postJson('/api/patients', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.age', 25);

        $this->assertDatabaseHas('patients', [
            'phone' => '+353878686889',
            'age' => 25,
        ]);
    }

    public function test_can_create_patient_with_only_age_and_auto_calculate_dob()
    {
        $payload = [
            'first_name' => 'Bob',
            'last_name' => 'Tester',
            'email' => 'bob.tester.' . uniqid() . '@example.com',
            'phone' => '+353878686890',
            'gender' => 'Male',
            'age' => 30,
            'branch_id' => $this->branch->id,
        ];

        $response = $this->actingAs($this->employee)->postJson('/api/patients', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.age', 30);
        $this->assertNotNull($response->json('data.date_of_birth'));

        $this->assertDatabaseHas('patients', [
            'phone' => '+353878686890',
            'age' => 30,
        ]);
    }

    public function test_matches_user_update_payload()
    {
        $patient = Patient::create([
            'first_name' => 'Derek',
            'last_name' => 'Bates',
            'phone' => '+353878686886',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
            'date_of_birth' => '1987-09-10',
        ]);

        $payload = [
            'first_name' => 'Derek',
            'last_name' => 'Bates',
            'address' => 'Cum at nisi quia ass',
            'age' => 39,
            'avatar' => 'https://picsum.photos/seed/p0.6371015806728099/200/200',
            'branch_id' => $this->branch->id,
            'date_of_birth' => '1987-09-10',
            'email' => 'dogemu@mailinator.com',
            'gender' => 'Male',
            'history' => 'Rerum molestias nost',
            'last_visit' => '2026-09-22',
            'next_visit' => '2026-09-23',
            'phone' => '+353878686886',
            'status' => 'Active',
        ];

        $response = $this->actingAs($this->employee)->putJson("/api/patients/{$patient->id}", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.age', 39);
        $response->assertJsonPath('data.date_of_birth', '1987-09-10T00:00:00.000000Z');

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'age' => 39,
            'address' => 'Cum at nisi quia ass',
        ]);
    }
}
