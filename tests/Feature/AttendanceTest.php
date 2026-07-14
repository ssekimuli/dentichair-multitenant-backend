<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\AttendanceRecord;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Create standard roles/permissions if needed
        RolePermission::create([
            'id' => 4,
            'name' => 'Dentist',
            'permissions' => []
        ]);

        $this->branch = Branch::create([
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'KES'
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);
    }

    /** @test */
    public function it_can_mark_attendance_with_list_payload()
    {
        $payload = [
            [
                'employee_id' => $this->employee->id,
                'date' => '2026-07-14',
                'status' => 'Present',
                'check_in' => '09:00',
                'check_out' => '07:02',
                'branch_id' => $this->branch->id
            ]
        ];

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/attendance', $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'date' => '2026-07-14',
            'status' => 'Present',
            'check_in' => '09:00',
            'check_out' => '07:02',
            'branch_id' => $this->branch->id
        ]);
    }

    /** @test */
    public function it_can_mark_attendance_with_single_object_payload()
    {
        $payload = [
            'employee_id' => $this->employee->id,
            'date' => '2026-07-14',
            'status' => 'Present',
            'check_in' => '09:00',
            'check_out' => '07:02',
            'branch_id' => $this->branch->id
        ];

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/attendance', $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'date' => '2026-07-14',
            'status' => 'Present',
            'check_in' => '09:00',
            'check_out' => '07:02',
            'branch_id' => $this->branch->id
        ]);
    }
}
