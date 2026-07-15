<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;
    private RolePermission $roleDentist;
    private RolePermission $roleAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleDentist = RolePermission::create([
            'id' => 1,
            'name' => 'Dentist',
            'permissions' => []
        ]);

        $this->roleAdmin = RolePermission::create([
            'id' => 2,
            'name' => 'Admin',
            'permissions' => []
        ]);

        $this->branch = Branch::create([
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'KES'
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => $this->roleDentist->id,
            'role' => $this->roleDentist->name,
            'branch_id' => $this->branch->id
        ]);
    }

    /** @test */
    public function it_blocks_actual_role_modifications()
    {
        // Try to update role_id to roleAdmin id
        $response = $this->actingAs($this->employee)
            ->putJson("/api/employees/{$this->employee->id}", [
                'role_id' => $this->roleAdmin->id,
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Role modifications are restricted',
        ]);
    }

    /** @test */
    public function it_allows_profile_updates_with_matching_role_or_role_id()
    {
        // Update details while keeping current role_id/role in payload
        $response = $this->actingAs($this->employee)
            ->putJson("/api/employees/{$this->employee->id}", [
                'name' => 'Dr. Lisa Cuddy Updated',
                'role_id' => $this->roleDentist->id,
                'role' => $this->roleDentist->id, // validation checks exists:role_permissions,id
            ]);

        $response->assertStatus(200);
        $this->assertEquals('Dr. Lisa Cuddy Updated', $this->employee->fresh()->name);
    }
}
