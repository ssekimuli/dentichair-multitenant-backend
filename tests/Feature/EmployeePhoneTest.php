<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePhoneTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $admin;
    private RolePermission $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = RolePermission::firstOrCreate(['id' => 1], [
            'name' => 'Dentist',
            'permissions' => []
        ]);

        $this->branch = Branch::firstOrCreate(['id' => 1], [
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'KES'
        ]);

        $this->admin = Employee::factory()->create([
            'role_id' => $this->role->id,
            'role' => $this->role->name,
            'branch_id' => $this->branch->id
        ]);
    }

    public function test_create_staff_saves_phone(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/employees', [
            'name' => 'New Staff',
            'email' => 'new.staff.' . uniqid() . '@example.com',
            'phone' => '+256 700 000000',
            'role_id' => $this->role->id,
            'salary' => 48000,
            'hire_date' => '2026-09-22',
            'branch_id' => $this->branch->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('employees', ['name' => 'New Staff', 'phone' => '+256 700 000000']);
    }

    public function test_update_staff_saves_phone(): void
    {
        $staff = Employee::factory()->create([
            'role_id' => $this->role->id,
            'role' => $this->role->name,
            'branch_id' => $this->branch->id,
            'phone' => null,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/employees/{$staff->id}", [
            'phone' => '+256 711 111111',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('employees', ['id' => $staff->id, 'phone' => '+256 711 111111']);
    }

    public function test_update_staff_can_clear_phone(): void
    {
        $staff = Employee::factory()->create([
            'role_id' => $this->role->id,
            'role' => $this->role->name,
            'branch_id' => $this->branch->id,
            'phone' => '+256 722 222222',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/employees/{$staff->id}", [
            'phone' => null,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('employees', ['id' => $staff->id, 'phone' => null]);
    }
}
