<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialSummaryDateRangeTest extends TestCase
{
    use RefreshDatabase;

    private Employee $user;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main', 'address' => 'x']);
        $role = RolePermission::create(['name' => 'Accountant', 'permissions' => []]);
        $this->user = Employee::factory()->create([
            'role' => 'Accountant', 'role_id' => $role->id, 'branch_id' => $this->branch->id,
        ]);

        // 300 paid in October 2026, 500 paid in November 2026.
        $this->invoice('2026-10-10', 300);
        $this->invoice('2026-11-05', 500);
    }

    private function invoice(string $issueDate, float $paid): void
    {
        Invoice::create([
            'patient_name' => 'Test Patient',
            'amount' => $paid,
            'paid_amount' => $paid,
            'status' => 'Paid',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'branch_id' => $this->branch->id,
            'category' => 'procedure-income',
        ]);
    }

    /** @test */
    public function a_single_date_is_rejected_instead_of_being_ignored(): void
    {
        $this->actingAs($this->user)->getJson(
            "/api/financial-summary?branch_id={$this->branch->id}&date_from=2026-11-01"
        )->assertStatus(422);

        $this->actingAs($this->user)->getJson(
            "/api/financial-summary?branch_id={$this->branch->id}&date_to=2026-11-30"
        )->assertStatus(422);
    }

    /** @test */
    public function end_date_before_start_date_is_rejected(): void
    {
        $this->actingAs($this->user)->getJson(
            "/api/financial-summary?branch_id={$this->branch->id}&date_from=2026-11-30&date_to=2026-11-01"
        )->assertStatus(422);
    }
}
