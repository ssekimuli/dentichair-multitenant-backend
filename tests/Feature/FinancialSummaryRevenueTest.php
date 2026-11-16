<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\RolePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accounting monthly finances and Reports total revenue must agree:
 * revenue = cash collected (paid_amount) on non-cancelled invoices.
 */
class FinancialSummaryRevenueTest extends TestCase
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
            'permissions' => [],
        ]);

        $this->branch = Branch::create([
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'KES',
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
        ]);

        // Paid in full, pending partial, unpaid, cancelled, void, and deleted — all in November 2026.
        $this->invoice('Pending', 500.00, 300.00, '2026-11-05');
        $this->invoice('Paid', 200.00, 200.00, '2026-11-10');
        $this->invoice('Pending', 400.00, 0.00, '2026-11-12');   // unpaid: must NOT count as revenue
        $this->invoice('Cancelled', 900.00, 900.00, '2026-11-15'); // must be excluded
        $this->invoice('Void', 700.00, 700.00, '2026-11-18');      // must be excluded
        $this->invoice('Paid', 600.00, 600.00, '2026-11-20', deleted: true); // must be excluded
    }

    /** @test */
    public function monthly_income_matches_reports_total_revenue_for_november(): void
    {
        // getFinancialSummary uses MySQL's DATE_FORMAT, so it cannot run on the SQLite test default.
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL (getFinancialSummary uses DATE_FORMAT).');
        }

        // Revenue = 300 + 200 = 500 (cash collected on active invoices).
        $reports = $this->actingAs($this->employee)
            ->getJson('/api/reports/overview?start_date=2026-11-01&end_date=2026-11-30');
        $reports->assertOk();
        $reportsRevenue = (float) $reports->json('financial_summary.total_income');

        $accounting = $this->actingAs($this->employee)
            ->getJson('/api/financial-summary?date_from=2026-11-01&date_to=2026-11-30&branch_id=' . $this->branch->id);
        $accounting->assertOk();

        $november = collect($accounting->json('monthlyFinancials'))->firstWhere('month', '2026-11');

        $this->assertEquals(500.00, (float) $november['income']);
        $this->assertEquals(500.00, (float) $accounting->json('total_income'));
        $this->assertEquals($reportsRevenue, (float) $november['income']);
    }

    private function invoice(string $status, float $amount, float $paid, string $issueDate, bool $deleted = false): void
    {
        $invoice = Invoice::create([
            'patient_name' => 'Test Patient',
            'amount' => $amount,
            'paid_amount' => $paid,
            'status' => $status,
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'branch_id' => $this->branch->id,
        ]);

        if ($deleted) {
            $invoice->delete();
        }
    }
}
