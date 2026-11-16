<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deleting an invoice must remove it from every income total immediately.
 */
class InvoiceDeleteTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = RolePermission::create(['id' => 1, 'name' => 'Dentist', 'permissions' => []]);
        $this->branch = Branch::create(['name' => 'Downtown Clinic', 'address' => '123 Main St', 'currency' => 'KES']);
        $this->employee = Employee::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branch->id]);
    }

    /** @test */
    public function deleting_a_paid_invoice_reduces_reports_and_income_records_totals(): void
    {
        Invoice::create($this->invoiceData(500, 300, '2026-11-05'));
        $bill = Invoice::create($this->invoiceData(200, 200, '2026-11-10'));

        $this->assertEquals('500.00', $this->reportsRevenue());
        $this->assertEquals('500.00', $this->incomeRecordsTotal());

        $this->actingAs($this->employee)->deleteJson('/api/invoices/' . $bill->id)->assertOk();

        $this->assertEquals('300.00', $this->reportsRevenue());
        $this->assertEquals('300.00', $this->incomeRecordsTotal());
    }

    /** @test */
    public function deleting_an_invoice_reduces_accounting_monthly_finances(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL (getFinancialSummary uses DATE_FORMAT).');
        }

        Invoice::create($this->invoiceData(500, 300, '2026-11-05'));
        $bill = Invoice::create($this->invoiceData(200, 200, '2026-11-10'));

        $this->assertEquals('500.00', $this->accountingNovemberIncome());

        $this->actingAs($this->employee)->deleteJson('/api/invoices/' . $bill->id)->assertOk();

        $this->assertEquals('300.00', $this->accountingNovemberIncome());
        $this->assertEquals('300.00', $this->reportsRevenue());
    }

    private function invoiceData(float $amount, float $paid, string $issueDate): array
    {
        return [
            'patient_name' => 'Test Patient',
            'amount' => $amount,
            'paid_amount' => $paid,
            'status' => $paid >= $amount ? 'Paid' : 'Pending',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'branch_id' => $this->branch->id,
        ];
    }

    private function reportsRevenue(): string
    {
        return $this->actingAs($this->employee)
            ->getJson('/api/reports/overview?start_date=2026-11-01&end_date=2026-11-30')
            ->json('financial_summary.total_income');
    }

    private function incomeRecordsTotal(): string
    {
        return $this->actingAs($this->employee)
            ->getJson('/api/income-records?date_from=2026-11-01&date_to=2026-11-30&branch_id=' . $this->branch->id)
            ->json('total');
    }

    private function accountingNovemberIncome(): string
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/financial-summary?date_from=2026-11-01&date_to=2026-11-30&branch_id=' . $this->branch->id);

        return collect($response->json('monthlyFinancials'))->firstWhere('month', '2026-11')['income'];
    }
}
