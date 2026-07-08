<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TreatmentBillingInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a branch and a patient
        $this->branch = Branch::create([
            'name' => 'Main Clinic',
            'address' => '123 Main St',
        ]);

        $this->patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);
    }

    /** @test */
    public function it_does_not_create_invoice_for_proposed_treatments()
    {
        $treatment = TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Teeth Cleaning',
            'cost' => 150.00,
            'status' => 'Proposed',
            'date' => '2026-07-08',
        ]);

        $this->assertDatabaseCount('invoices', 0);
        $this->assertNull($treatment->fresh()->invoice_id);
    }

    /** @test */
    public function it_creates_invoice_when_completed_treatment_is_added()
    {
        $treatment = TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Teeth Cleaning',
            'cost' => 150.00,
            'status' => 'Completed',
            'date' => '2026-07-08',
        ]);

        $this->assertDatabaseCount('invoices', 1);

        $invoice = Invoice::first();
        $this->assertEquals($this->patient->id, $invoice->patient_id);
        $this->assertEquals('John Doe', $invoice->patient_name);
        $this->assertEquals(150.00, $invoice->amount);
        $this->assertEquals('2026-07-08', $invoice->issue_date->format('Y-m-d'));
        $this->assertEquals('Pending', $invoice->status);

        // Check relationship and link
        $this->assertEquals('inv' . $invoice->id, $treatment->fresh()->invoice_id);
        $this->assertDatabaseCount('invoice_treatments', 1);
        $this->assertEquals('Teeth Cleaning', $invoice->treatments()->first()->procedure);
        $this->assertEquals(150.00, $invoice->treatments()->first()->cost);
    }

    /** @test */
    public function it_groups_multiple_completed_treatments_on_same_date_into_single_invoice()
    {
        $treatment1 = TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Teeth Cleaning',
            'cost' => 150.00,
            'status' => 'Completed',
            'date' => '2026-07-08',
        ]);

        $treatment2 = TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Composite Filling',
            'cost' => 200.00,
            'status' => 'Completed',
            'date' => '2026-07-08',
        ]);

        // Should still be 1 invoice
        $this->assertDatabaseCount('invoices', 1);

        $invoice = Invoice::first();
        $this->assertEquals(350.00, $invoice->amount);
        $this->assertDatabaseCount('invoice_treatments', 2);

        $this->assertEquals('inv' . $invoice->id, $treatment1->fresh()->invoice_id);
        $this->assertEquals('inv' . $invoice->id, $treatment2->fresh()->invoice_id);
    }

    /** @test */
    public function it_creates_separate_invoices_for_different_dates()
    {
        TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Teeth Cleaning',
            'cost' => 150.00,
            'status' => 'Completed',
            'date' => '2026-07-08',
        ]);

        TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Composite Filling',
            'cost' => 200.00,
            'status' => 'Completed',
            'date' => '2026-07-09',
        ]);

        // Should be 2 invoices
        $this->assertDatabaseCount('invoices', 2);
    }

    /** @test */
    public function it_updates_invoice_when_completed_treatment_is_deleted()
    {
        $treatment1 = TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Teeth Cleaning',
            'cost' => 150.00,
            'status' => 'Completed',
            'date' => '2026-07-08',
        ]);

        $treatment2 = TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Composite Filling',
            'cost' => 200.00,
            'status' => 'Completed',
            'date' => '2026-07-08',
        ]);

        $invoice = Invoice::first();
        $this->assertEquals(350.00, $invoice->amount);

        // Delete one treatment
        $treatment2->delete();

        $invoice = $invoice->fresh();
        $this->assertEquals(150.00, $invoice->amount);
        $this->assertDatabaseCount('invoice_treatments', 1);

        // Delete the remaining treatment
        $treatment1->delete();

        // Invoice should be deleted because it has no treatments, other charges, or payments
        $this->assertDatabaseCount('invoices', 0);
    }

    /** @test */
    public function it_updates_invoices_correctly_when_treatment_date_changes()
    {
        $treatment = TreatmentPlan::create([
            'patient_id' => $this->patient->id,
            'procedure' => 'Teeth Cleaning',
            'cost' => 150.00,
            'status' => 'Completed',
            'date' => '2026-07-08',
        ]);

        $invoice1 = Invoice::first();
        $this->assertNotNull($invoice1);
        $this->assertEquals('2026-07-08', $invoice1->issue_date->format('Y-m-d'));

        // Change treatment date to next day
        $treatment->update(['date' => '2026-07-09']);

        // Old invoice should be deleted (or cleaned up), and new invoice created
        $this->assertDatabaseCount('invoices', 1);
        
        $newInvoice = Invoice::first();
        $this->assertEquals('2026-07-09', $newInvoice->issue_date->format('Y-m-d'));
        $this->assertEquals('inv' . $newInvoice->id, $treatment->fresh()->invoice_id);
    }
}
