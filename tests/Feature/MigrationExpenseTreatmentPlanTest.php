<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Patient;
use App\Models\RolePermission;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MigrationExpenseTreatmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'id' => 1,
            'name' => 'Main Clinic',
            'address' => '123 Main St',
        ]);

        RolePermission::create([
            'id' => 4,
            'name' => 'Dentist',
            'permissions' => [],
        ]);

        $this->user = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function migrate(string $section, string $csv, string $name = 'import.csv')
    {
        $file = UploadedFile::fake()->createWithContent($name, $csv);

        return $this->actingAs($this->user)->postJson('/api/migrate', [
            'file' => $file,
            'section' => $section,
        ]);
    }

    /** @test */
    public function it_imports_expenses_and_creates_missing_categories(): void
    {
        $csv = "Date,Category,Description,Amount,Branch ID\n"
            . "2026-03-01,Utilities,Electricity bill,450.50,1\n"
            . "2026-03-02,Supplies,Gloves,120,1\n";

        $response = $this->migrate('expense', $csv);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('stats.imported', 2);

        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseHas('expenses', [
            'branch_id' => 1,
            'date' => '2026-03-01',
            'description' => 'Electricity bill',
            'category' => 'Utilities',
        ]);
        $this->assertSame(2, ExpenseCategory::where('branch_id', 1)->count());
    }

    /** @test */
    public function it_reuses_existing_category_matched_case_insensitively(): void
    {
        $category = ExpenseCategory::create(['name' => 'Utilities', 'branch_id' => 1]);

        $this->migrate('expenses', "Date,Category,Amount,Branch ID\n2026-03-01,utilities,80,1\n")
            ->assertStatus(200);

        $this->assertSame(1, ExpenseCategory::where('branch_id', 1)->count());
        $this->assertSame($category->id, Expense::first()->expense_category_id);
    }

    /** @test */
    public function reimporting_the_same_expense_does_not_duplicate_it(): void
    {
        $csv = "Date,Category,Description,Amount,Branch ID\n2026-03-01,Utilities,Electricity,450,1\n";

        $this->migrate('expense', $csv)->assertStatus(200);
        $this->migrate('expense', $csv)->assertStatus(200);

        $this->assertDatabaseCount('expenses', 1);
    }

    /** @test */
    public function expense_rows_with_missing_date_or_amount_are_reported(): void
    {
        $csv = "Date,Category,Description,Amount,Branch ID\n"
            . ",Utilities,No date,10,1\n"
            . "2026-03-01,Utilities,Bad amount,abc,1\n";

        $response = $this->migrate('expense', $csv);

        $response->assertStatus(400)
            ->assertJsonPath('stats.failed', 2)
            ->assertJsonPath('stats.imported', 0);

        $this->assertDatabaseCount('expenses', 0);
    }

    /** @test */
    public function it_imports_treatment_plans_through_the_treatment_plan_section(): void
    {
        $patient = Patient::create([
            'first_name' => 'Plan',
            'last_name' => 'Patient',
            'email' => 'plan.patient@example.com',
            'phone' => '0700000001',
            'gender' => 'Other',
            'date_of_birth' => '1990-01-01',
            'status' => 'Active',
            'branch_id' => $this->branch->id,
        ]);

        $csv = "Patient Email,Procedure,Tooth,Status,Cost,Date\n"
            . "plan.patient@example.com,Composite filling,16,Proposed,150,2026-03-05\n"
            . "plan.patient@example.com,Scaling,,Completed,80,2026-03-06\n";

        $response = $this->migrate('treatment_plan', $csv);

        $response->assertStatus(200)
            ->assertJsonPath('stats.imported', 2);

        $this->assertDatabaseHas('treatment_plans', [
            'patient_id' => $patient->id,
            'procedure' => 'Composite filling',
            'tooth' => 16,
            'status' => 'Proposed',
        ]);
        $this->assertDatabaseHas('treatment_plans', [
            'patient_id' => $patient->id,
            'procedure' => 'Scaling',
            'status' => 'Completed',
        ]);
        $this->assertSame(2, TreatmentPlan::where('patient_id', $patient->id)->count());
    }

    /** @test */
    public function it_imports_payroll_for_existing_employees_and_updates_same_period(): void
    {
        $email = $this->user->email;
        $csv = "Employee Email,Pay Period,Gross Pay,Deductions,Pay Date,Status\n"
            . "{$email},2026-03,5000,500,2026-03-31,Paid\n";

        $this->migrate('payroll', $csv)
            ->assertStatus(200)
            ->assertJsonPath('stats.imported', 1);

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $this->user->id,
            'pay_period' => '2026-03',
            'gross_pay' => 5000,
            'net_pay' => 4500,
            'status' => 'Paid',
            'approval_status' => 'Approved',
        ]);

        // Same employee + pay period updates the existing row.
        $this->migrate('payroll', "Employee Email,Pay Period,Gross Pay,Deductions,Pay Date\n{$email},2026-03,5200,500,2026-03-31\n")
            ->assertStatus(200);

        $this->assertDatabaseCount('payrolls', 1);
        $this->assertDatabaseHas('payrolls', ['gross_pay' => 5200, 'net_pay' => 4700]);
    }

    /** @test */
    public function payroll_rows_for_unknown_employees_are_reported(): void
    {
        $csv = "Employee Email,Pay Period,Gross Pay,Deductions,Pay Date\n"
            . "nobody@example.com,2026-03,1000,100,2026-03-31\n";

        $this->migrate('payroll', $csv)
            ->assertStatus(400)
            ->assertJsonPath('stats.failed', 1);

        $this->assertDatabaseCount('payrolls', 0);
    }
}
