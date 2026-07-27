<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = RolePermission::create([
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
            'role_id' => $role->id,
            'branch_id' => $this->branch->id
        ]);
    }

    /** @test */
    public function it_can_retrieve_activity_logs()
    {
        // Log some activity while logged in
        $this->actingAs($this->employee);

        ActivityLog::log('CREATE', 'Created a new patient', ['patient_id' => 1]);
        ActivityLog::log('UPDATE', 'Updated treatment plan', ['plan_id' => 2]);

        $response = $this->getJson('/api/activity-logs?page=1&per_page=20');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'current_page',
            'data' => [
                '*' => [
                    'id',
                    'user_id',
                    'action',
                    'description',
                    'metadata',
                    'user_name',
                ]
            ],
            'first_page_url',
            'from',
            'last_page',
            'last_page_url',
            'links',
            'next_page_url',
            'path',
            'per_page',
            'prev_page_url',
            'to',
            'total'
        ]);

        $this->assertCount(4, $response->json('data'));
    }

    /** @test */
    public function it_can_filter_activity_logs_by_action()
    {
        $this->actingAs($this->employee);

        ActivityLog::log('CREATE', 'Created a new patient', ['patient_id' => 1]);
        ActivityLog::log('UPDATE', 'Updated treatment plan', ['plan_id' => 2]);

        $response = $this->getJson('/api/activity-logs?action=CREATE');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('CREATE', $response->json('data.0.action'));
    }

    /** @test */
    public function it_can_filter_activity_logs_by_search()
    {
        $this->actingAs($this->employee);

        ActivityLog::log('CREATE', 'Created a new patient', ['patient_id' => 1]);
        ActivityLog::log('UPDATE', 'Updated treatment plan', ['plan_id' => 2]);

        $response = $this->getJson('/api/activity-logs?search=treatment');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('UPDATE', $response->json('data.0.action'));
    }
}
