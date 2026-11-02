<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\Quotation;
use App\Models\RolePermission;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuotationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected Employee $admin;
    protected Employee $staffB;
    protected Employee $dentist;
    protected Patient $patientA;
    protected Client $labClient;
    protected RolePermission $superAdminRole;
    protected RolePermission $receptionistRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminRole = RolePermission::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        $this->receptionistRole = RolePermission::create([
            'name' => 'Receptionist',
            'permissions' => ['billing:read', 'billing:create', 'billing:update'],
        ]);

        $this->branchA = Branch::create([
            'name' => 'Apex Dental Care',
            'address' => '100 Medical Blvd',
            'currency' => 'USD',
        ]);

        $this->branchB = Branch::create([
            'name' => 'North Branch',
            'address' => '200 North Way',
            'currency' => 'USD',
        ]);

        $this->admin = Employee::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@dentichair.com',
            'role' => 'Super Admin',
            'role_id' => $this->superAdminRole->id,
            'branch_id' => $this->branchA->id,
            'status' => 'Active',
        ]);

        $this->staffB = Employee::factory()->create([
            'name' => 'Branch B Staff',
            'email' => 'staffb@dentichair.com',
            'role' => 'Receptionist',
            'role_id' => $this->receptionistRole->id,
            'branch_id' => $this->branchB->id,
            'status' => 'Active',
        ]);

        $this->dentist = Employee::factory()->create([
            'name' => 'Dr. Jane Smith',
            'email' => 'jane.smith@dentichair.com',
            'role' => 'Dentist',
            'role_id' => $this->superAdminRole->id,
            'branch_id' => $this->branchA->id,
            'status' => 'Active',
        ]);

        $this->patientA = Patient::create([
            'first_name' => 'Michael',
            'last_name' => 'Scott',
            'email' => 'michael.scott@dundermifflin.com',
            'phone' => '+1555123456',
            'date_of_birth' => '1980-05-15',
            'gender' => 'male',
            'branch_id' => $this->branchA->id,
            'status' => 'Active',
        ]);

        $this->labClient = Client::create([
            'name' => 'Precision Dental Lab LLC',
            'email' => 'orders@precisionlab.com',
            'phone' => '+1555987654',
            'address' => '450 Industrial Parkway',
        ]);
    }

    /** @test */
    public function it_creates_a_patient_quotation_manually()
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'dentist_id' => $this->dentist->id,
            'quotation_date' => now()->format('Y-m-d'),
            'valid_until' => now()->addDays(14)->format('Y-m-d'),
            'discount_type' => 'percentage',
            'discount_amount' => 10,
            'tax_rate' => 5,
            'notes' => 'Patient requested ceramic options.',
            'items' => [
                [
                    'description' => 'Composite Filling',
                    'tooth_number' => '14',
                    'quantity' => 1,
                    'unit_price' => 150.00,
                ],
                [
                    'description' => 'Root Canal Treatment',
                    'tooth_number' => '14',
                    'quantity' => 1,
                    'unit_price' => 500.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/quotations', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.customer_type', 'patient');
        $response->assertJsonPath('data.patient_id', $this->patientA->id);
        $response->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('quotations', [
            'patient_id' => $this->patientA->id,
            'branch_id' => $this->branchA->id,
            'customer_type' => 'patient',
            'status' => 'draft',
        ]);

        $this->assertDatabaseCount('quotation_items', 2);
    }

    /** @test */
    public function it_creates_a_patient_quotation_from_treatment_plans()
    {
        Sanctum::actingAs($this->admin);

        $tp1 = TreatmentPlan::create([
            'patient_id' => $this->patientA->id,
            'procedure' => 'Porcelain Crown',
            'tooth' => '21',
            'cost' => 800.00,
            'status' => 'Proposed',
            'branch_id' => $this->branchA->id,
            'doctor_id' => $this->dentist->id,
            'date' => now()->format('Y-m-d'),
        ]);

        $tp2 = TreatmentPlan::create([
            'patient_id' => $this->patientA->id,
            'procedure' => 'Dental Cleaning',
            'tooth' => null,
            'cost' => 120.00,
            'status' => 'Proposed',
            'branch_id' => $this->branchA->id,
            'doctor_id' => $this->dentist->id,
            'date' => now()->format('Y-m-d'),
        ]);

        $payload = [
            'treatment_plan_item_ids' => [$tp1->id, $tp2->id],
            'notes' => 'Generated from treatment plan.',
            'additional_items' => [
                [
                    'description' => 'Oral Hygiene Kit',
                    'quantity' => 1,
                    'unit_price' => 30.00,
                ]
            ],
        ];

        $response = $this->postJson("/api/v1/patients/{$this->patientA->id}/quotations/from-treatment-plan", $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.customer_type', 'patient');
        $response->assertJsonPath('data.source_type', 'mixed');

        $this->assertDatabaseHas('quotations', [
            'patient_id' => $this->patientA->id,
            'source_type' => 'mixed',
        ]);

        // 2 treatment plan items + 1 additional item = 3 items
        $this->assertDatabaseCount('quotation_items', 3);
    }

    /** @test */
    public function it_creates_a_laboratory_quotation_manually()
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'customer_type' => 'lab_client',
            'lab_client_id' => $this->labClient->id,
            'quotation_date' => now()->format('Y-m-d'),
            'valid_until' => now()->addDays(30)->format('Y-m-d'),
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
            'items' => [
                [
                    'description' => 'Zirconia Bridge 3-Units',
                    'tooth_number' => '11-13',
                    'material' => 'Zirconia',
                    'shade' => 'A2',
                    'quantity' => 1,
                    'unit_price' => 450.00,
                ],
                [
                    'description' => 'Rush Delivery Fee',
                    'quantity' => 1,
                    'unit_price' => 50.00,
                ]
            ],
        ];

        $response = $this->postJson('/api/v1/quotations', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.customer_type', 'lab_client');
        $response->assertJsonPath('data.lab_client_id', $this->labClient->id);
        $response->assertJsonPath('data.patient_id', null);

        $this->assertDatabaseHas('quotations', [
            'lab_client_id' => $this->labClient->id,
            'customer_type' => 'lab_client',
            'branch_id' => $this->branchA->id,
        ]);
    }

    /** @test */
    public function it_creates_a_laboratory_quotation_from_lab_order()
    {
        Sanctum::actingAs($this->admin);

        $labOrder = LaboratoryOrder::create([
            'client_id' => $this->labClient->id,
            'item' => 'Full Denture Upper',
            'tooth' => 'Upper Arch',
            'material' => 'Acrylic',
            'shade' => 'B1',
            'applied_price' => 600.00,
            'status' => 'Received',
            'branch_id' => $this->branchA->id,
            'due_date' => now()->addDays(10)->format('Y-m-d'),
        ]);

        $payload = [
            'lab_order_id' => $labOrder->id,
            'notes' => 'Lab order quotation.',
        ];

        $response = $this->postJson("/api/v1/lab-orders/{$labOrder->id}/quotations", $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.customer_type', 'lab_client');
        $response->assertJsonPath('data.lab_order_id', $labOrder->id);
        $response->assertJsonPath('data.lab_client_id', $this->labClient->id);
    }

    /** @test */
    public function it_rejects_quotation_with_both_patient_and_lab_client()
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'lab_client_id' => $this->labClient->id,
            'items' => [
                [
                    'description' => 'Consultation',
                    'quantity' => 1,
                    'unit_price' => 50.00,
                ]
            ],
        ];

        $response = $this->postJson('/api/v1/quotations', $payload);
        $response->assertStatus(422);
    }

    /** @test */
    public function it_rejects_quotation_with_neither_customer_populated()
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'customer_type' => 'patient',
            'items' => [
                [
                    'description' => 'Consultation',
                    'quantity' => 1,
                    'unit_price' => 50.00,
                ]
            ],
        ];

        $response = $this->postJson('/api/v1/quotations', $payload);
        $response->assertStatus(422);
    }

    /** @test */
    public function it_lists_and_filters_quotations()
    {
        Sanctum::actingAs($this->admin);

        // Create 2 quotations
        $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Scaling', 'quantity' => 1, 'unit_price' => 80]],
        ]);

        $this->postJson('/api/v1/quotations', [
            'customer_type' => 'lab_client',
            'lab_client_id' => $this->labClient->id,
            'items' => [['description' => 'Cast Partial', 'quantity' => 1, 'unit_price' => 400]],
        ]);

        // List all
        $listRes = $this->getJson('/api/v1/quotations');
        $listRes->assertStatus(200);
        $this->assertCount(2, $listRes->json('data'));

        // Filter by customer_type = patient
        $filterRes = $this->getJson('/api/v1/quotations?customer_type=patient');
        $filterRes->assertStatus(200);
        $this->assertCount(1, $filterRes->json('data'));
        $this->assertEquals('patient', $filterRes->json('data.0.customer_type'));

        // Search by patient name
        $searchRes = $this->getJson('/api/v1/quotations?search=Michael');
        $searchRes->assertStatus(200);
        $this->assertCount(1, $searchRes->json('data'));
    }

    /** @test */
    public function it_updates_draft_quotation_and_prevents_updating_approved_quotation()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Old Item', 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Update while draft
        $updateRes = $this->putJson("/api/v1/quotations/{$quotationId}", [
            'notes' => 'Updated notes',
            'items' => [['description' => 'New Item', 'quantity' => 2, 'unit_price' => 150]],
        ]);
        $updateRes->assertStatus(200);
        $this->assertEquals(300.00, $updateRes->json('data.total'));

        // Transition to sent then approved
        $quotation = Quotation::find($quotationId);
        $quotation->update(['status' => 'approved']);

        // Attempt edit on approved quotation -> should fail
        $failRes = $this->putJson("/api/v1/quotations/{$quotationId}", [
            'notes' => 'Attempted silent edit',
        ]);
        $failRes->assertStatus(422);
    }

    /** @test */
    public function staff_can_approve_and_reject_quotations()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Send quotation
        $this->postJson("/api/v1/quotations/{$quotationId}/send", ['channels' => ['email']]);
        $this->assertEquals('sent', Quotation::find($quotationId)->status);

        // Staff approve
        $approveRes = $this->postJson("/api/v1/quotations/{$quotationId}/approve", [
            'notes' => 'Patient signed physical copy in office.',
        ]);
        $approveRes->assertStatus(200);
        $this->assertEquals('approved', Quotation::find($quotationId)->status);
        $this->assertDatabaseHas('quotation_approvals', [
            'quotation_id' => $quotationId,
            'decision' => 'approved',
            'approval_method' => 'clinic_staff',
        ]);
    }

    /** @test */
    public function customer_can_approve_through_public_link()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Bridge', 'quantity' => 1, 'unit_price' => 700]],
        ]);
        $quotationId = $createRes->json('data.id');

        $sendRes = $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $rawToken = $sendRes->json('data.token');
        $this->assertNotEmpty($rawToken);

        // Public view
        $viewRes = $this->getJson("/api/v1/public/quotations/{$rawToken}");
        $viewRes->assertStatus(200);
        $viewRes->assertJsonPath('data.can_be_decided', true);

        // Public approve
        $approveRes = $this->postJson("/api/v1/public/quotations/{$rawToken}/approve", [
            'customer_name' => 'Michael Scott',
            'consent_confirmed' => true,
        ]);
        $approveRes->assertStatus(200);

        $this->assertEquals('approved', Quotation::find($quotationId)->status);
        $this->assertDatabaseHas('quotation_approvals', [
            'quotation_id' => $quotationId,
            'decision' => 'approved',
            'approval_method' => 'patient_link',
        ]);

        // Second approval attempt with same token must fail (single-use)
        $secondApproveRes = $this->postJson("/api/v1/public/quotations/{$rawToken}/approve", [
            'customer_name' => 'Michael Scott',
            'consent_confirmed' => true,
        ]);
        $secondApproveRes->assertStatus(422);
    }

    /** @test */
    public function customer_can_reject_through_public_link()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'lab_client',
            'lab_client_id' => $this->labClient->id,
            'items' => [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $quotationId = $createRes->json('data.id');

        $sendRes = $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $rawToken = $sendRes->json('data.token');

        // Public reject
        $rejectRes = $this->postJson("/api/v1/public/quotations/{$rawToken}/reject", [
            'reason' => 'Too expensive, choosing different material.',
        ]);
        $rejectRes->assertStatus(200);

        $this->assertEquals('rejected', Quotation::find($quotationId)->status);
        $this->assertDatabaseHas('quotation_approvals', [
            'quotation_id' => $quotationId,
            'decision' => 'rejected',
            'approval_method' => 'lab_client_link',
        ]);
    }

    /** @test */
    public function it_converts_approved_patient_quotation_into_invoice()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'dentist_id' => $this->dentist->id,
            'discount_type' => 'fixed',
            'discount_amount' => 50.00,
            'tax_rate' => 10.00,
            'items' => [
                ['description' => 'Porcelain Crown', 'tooth_number' => '11', 'quantity' => 1, 'unit_price' => 600.00],
                ['description' => 'Post & Core', 'tooth_number' => '11', 'quantity' => 1, 'unit_price' => 200.00],
            ],
        ]);
        $quotationId = $createRes->json('data.id');

        // Send & Approve
        $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $this->postJson("/api/v1/quotations/{$quotationId}/approve", ['notes' => 'Patient signed']);

        // Convert to Invoice
        $convertRes = $this->postJson("/api/v1/quotations/{$quotationId}/convert");
        $convertRes->assertStatus(200);

        $invoiceId = $convertRes->json('data.invoice.id');
        $this->assertNotNull($invoiceId);

        $invoice = Invoice::with('treatments')->find($invoiceId);
        $this->assertNotNull($invoice);
        $this->assertEquals($this->patientA->id, $invoice->patient_id);
        $this->assertEquals($this->branchA->id, $invoice->branch_id);
        $this->assertEquals('Pending', $invoice->status);
        $this->assertEquals(0.00, (float) $invoice->paid_amount);

        // Check quotation updated
        $quotation = Quotation::find($quotationId);
        $this->assertEquals('converted', $quotation->status);
        $this->assertEquals($invoice->id, $quotation->converted_invoice_id);

        // Verify treatment items preserved
        $this->assertCount(2, $invoice->treatments);
        $this->assertStringContainsString('Porcelain Crown', $invoice->treatments[0]->procedure);
        $this->assertStringContainsString('Tooth #11', $invoice->treatments[0]->procedure);

        // Converting again must fail (prevent duplicate invoice generation)
        $duplicateRes = $this->postJson("/api/v1/quotations/{$quotationId}/convert");
        $duplicateRes->assertStatus(422);
    }

    /** @test */
    public function it_converts_approved_lab_quotation_into_invoice()
    {
        Sanctum::actingAs($this->admin);

        $labOrder = LaboratoryOrder::create([
            'client_id' => $this->labClient->id,
            'item' => 'Metal Ceramic Crown',
            'applied_price' => 250.00,
            'status' => 'Received',
            'branch_id' => $this->branchA->id,
        ]);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'lab_client',
            'lab_client_id' => $this->labClient->id,
            'lab_order_id' => $labOrder->id,
            'items' => [
                ['description' => 'Metal Ceramic Crown', 'quantity' => 1, 'unit_price' => 250.00],
            ],
        ]);
        $quotationId = $createRes->json('data.id');

        $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $this->postJson("/api/v1/quotations/{$quotationId}/approve");

        $convertRes = $this->postJson("/api/v1/quotations/{$quotationId}/convert");
        $convertRes->assertStatus(200);

        $invoiceId = $convertRes->json('data.invoice.id');
        $invoice = Invoice::find($invoiceId);

        $this->assertNull($invoice->patient_id);
        $this->assertEquals($this->labClient->id, $invoice->client_id);
        $this->assertEquals('lab-income', $invoice->category);
        $this->assertEquals($this->labClient->name, $invoice->patient_name);

        // Lab order is linked to invoice
        $this->assertEquals($invoice->id, $labOrder->fresh()->invoice_id);
    }

    /** @test */
    public function it_prevents_converting_an_unapproved_quotation()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Extraction', 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Quotation is still draft
        $convertRes = $this->postJson("/api/v1/quotations/{$quotationId}/convert");
        $convertRes->assertStatus(422);
    }

    /** @test */
    public function it_allows_payments_on_converted_invoice()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Filling', 'quantity' => 1, 'unit_price' => 200]],
        ]);
        $quotationId = $createRes->json('data.id');

        $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $this->postJson("/api/v1/quotations/{$quotationId}/approve");
        $convertRes = $this->postJson("/api/v1/quotations/{$quotationId}/convert");
        $invoiceId = $convertRes->json('data.invoice.id');

        // Add payment to invoice using existing invoice payment route
        $paymentRes = $this->postJson("/api/invoices/{$invoiceId}/payments", [
            'amount' => 100.00,
            'method' => 'Cash',
            'date' => now()->format('Y-m-d'),
        ]);

        $paymentRes->assertStatus(200);

        $invoice = Invoice::find($invoiceId);
        $this->assertEquals(100.00, (float) $invoice->paid_amount);
        $this->assertEquals(200.00, (float) $invoice->amount);
        $this->assertEquals('Pending', $invoice->status);
        $this->assertEquals('Cash', $invoice->payment_method);
    }

    /** @test */
    public function it_enforces_multi_tenant_branch_isolation()
    {
        // Admin creates quotation in Branch A
        Sanctum::actingAs($this->admin);
        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'X-Ray', 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Staff from Branch B attempts to view/convert quotation of Branch A
        Sanctum::actingAs($this->staffB);

        $viewRes = $this->getJson("/api/v1/quotations/{$quotationId}");
        $viewRes->assertStatus(403);

        $convertRes = $this->postJson("/api/v1/quotations/{$quotationId}/convert");
        $convertRes->assertStatus(403);
    }

    /** @test */
    public function it_generates_pdf_for_quotation()
    {
        Sanctum::actingAs($this->admin);

        // Configure branch with a custom logo (data URI)
        $this->branchA->update([
            'logo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        ]);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Whitening', 'quantity' => 1, 'unit_price' => 250]],
        ]);
        $quotationId = $createRes->json('data.id');

        $pdfRes = $this->get("/api/v1/quotations/{$quotationId}/pdf");
        $pdfRes->assertStatus(200);
        $pdfRes->assertHeader('Content-Type', 'application/pdf');
        $pdfContent = $pdfRes->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfContent);
        // Verify logo object is embedded in PDF
        $this->assertStringContainsString('/Subtype /Image', $pdfContent);
        $this->assertStringContainsString('/Im1', $pdfContent);

        // HTML format includes logo
        $htmlRes = $this->get("/api/v1/quotations/{$quotationId}/pdf?format=html");
        $htmlRes->assertStatus(200);
        $this->assertStringContainsString('Whitening', $htmlRes->getContent());
        $this->assertStringContainsString('clinic-logo-img', $htmlRes->getContent());
    }

    /** @test */
    public function it_revises_and_renews_quotations()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'valid_until' => now()->subDay()->format('Y-m-d'), // expired
            'items' => [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 500]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Renew
        $renewRes = $this->postJson("/api/v1/quotations/{$quotationId}/renew", [
            'valid_until' => now()->addDays(20)->format('Y-m-d'),
        ]);
        $renewRes->assertStatus(200);
        $this->assertEquals(now()->addDays(20)->format('Y-m-d'), $renewRes->json('data.valid_until'));

        // Revise
        $reviseRes = $this->postJson("/api/v1/quotations/{$quotationId}/revise", [
            'notes' => 'Revision with 10% discount',
            'discount_type' => 'percentage',
            'discount_amount' => 10,
        ]);
        $reviseRes->assertStatus(201);
        $this->assertEquals(1, $reviseRes->json('data.revision_number'));
        $this->assertEquals($quotationId, $reviseRes->json('data.original_quotation_id'));
        $this->assertEquals(450.00, $reviseRes->json('data.total'));
    }

    /** @test */
    public function expired_quotation_cannot_be_approved_without_renewal()
    {
        Sanctum::actingAs($this->admin);

        $quotation = Quotation::create([
            'quotation_number' => 'QUO-EXP-1',
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'branch_id' => $this->branchA->id,
            'quotation_date' => now()->subDays(40)->format('Y-m-d'),
            'valid_until' => now()->subDays(10)->format('Y-m-d'),
            'subtotal' => 100.00,
            'total' => 100.00,
            'status' => 'sent',
        ]);

        $response = $this->postJson("/api/v1/quotations/{$quotation->id}/approve", [
            'notes' => 'Late approval attempt',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function invalid_and_revoked_tokens_cannot_be_used()
    {
        // 1. Completely bogus token
        $badRes = $this->getJson('/api/v1/public/quotations/invalid-random-token-12345');
        $badRes->assertStatus(422);

        // 2. Revoked token
        Sanctum::actingAs($this->admin);
        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Braces', 'quantity' => 1, 'unit_price' => 1200]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Dispatched first time -> generates Token 1
        $send1 = $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $token1 = $send1->json('data.token');

        // Dispatched second time -> Token 1 is revoked, Token 2 generated
        $send2 = $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $token2 = $send2->json('data.token');

        // Attempting to view with revoked Token 1 should fail
        $revokedRes = $this->getJson("/api/v1/public/quotations/{$token1}");
        $revokedRes->assertStatus(422);

        // Token 2 works
        $validRes = $this->getJson("/api/v1/public/quotations/{$token2}");
        $validRes->assertStatus(200);
    }

    /** @test */
    public function it_retrieves_complete_quotation_history()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Implant', 'quantity' => 1, 'unit_price' => 1500]],
        ]);
        $quotationId = $createRes->json('data.id');

        $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $this->postJson("/api/v1/quotations/{$quotationId}/approve", ['notes' => 'Patient agreed']);

        $historyRes = $this->getJson("/api/v1/quotations/{$quotationId}/history");
        $historyRes->assertStatus(200);
        $historyRes->assertJsonStructure([
            'data' => [
                'status_history',
                'approvals',
            ]
        ]);
        $this->assertNotEmpty($historyRes->json('data.status_history'));
        $this->assertNotEmpty($historyRes->json('data.approvals'));
    }

    /** @test */
    public function it_validates_and_dispatches_email_with_pdf_and_description()
    {
        \Illuminate\Support\Facades\Mail::fake();
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id, // has email and phone
            'items' => [['description' => 'Teeth Cleaning', 'quantity' => 1, 'unit_price' => 120]],
        ]);
        $quotationId = $createRes->json('data.id');

        $sendRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['email'],
            'message' => 'Please find your treatment estimate attached.',
        ]);

        $sendRes->assertStatus(200);
        $sendRes->assertJsonPath('data.channels.email.success', true);
        $sendRes->assertJsonPath('data.channels.email.has_pdf', true);
        $sendRes->assertJsonPath('data.email', $this->patientA->email);
        $sendRes->assertJsonPath('data.channels.email.recipient', $this->patientA->email);

        \Illuminate\Support\Facades\Mail::assertSent(\Illuminate\Mail\Mailable::class, 0); // Sent via raw
    }

    /** @test */
    public function it_requires_email_when_email_channel_selected_and_allows_custom_email_input()
    {
        Sanctum::actingAs($this->admin);

        // Patient without email
        $patientNoContact = Patient::create([
            'first_name' => 'Dwight',
            'last_name' => 'Schrute',
            'date_of_birth' => '1982-01-20',
            'gender' => 'male',
            'branch_id' => $this->branchA->id,
            'status' => 'Active',
        ]);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $patientNoContact->id,
            'items' => [['description' => 'Extraction', 'quantity' => 1, 'unit_price' => 200]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Sending via email without email on file or in request should fail validation
        $failRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['email'],
        ]);
        $failRes->assertStatus(422);
        $failRes->assertJsonValidationErrors(['email']);

        // Providing email in request should pass and dispatch
        \Illuminate\Support\Facades\Mail::fake();
        $successRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['email'],
            'email' => 'dwight@schrute-farms.com',
            'message' => 'Your quotation is ready.',
        ]);

        $successRes->assertStatus(200);
        $successRes->assertJsonPath('data.email', 'dwight@schrute-farms.com');
        $successRes->assertJsonPath('data.channels.email.recipient', 'dwight@schrute-farms.com');
        $successRes->assertJsonPath('data.channels.email.has_pdf', true);

        // Patient profile email should be synced
        $this->assertEquals('dwight@schrute-farms.com', $patientNoContact->fresh()->email);
    }

    /** @test */
    public function it_validates_and_dispatches_whatsapp_with_pdf_media_and_caption()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id, // has phone +1555123456
            'items' => [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 800]],
        ]);
        $quotationId = $createRes->json('data.id');

        $sendRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['whatsapp'],
            'message' => 'Hello Michael, your dental quotation is attached.',
        ]);

        $sendRes->assertStatus(200);
        $sendRes->assertJsonPath('data.channels.whatsapp.success', true);
        $sendRes->assertJsonPath('data.channels.whatsapp.has_pdf', true);
        $sendRes->assertJsonPath('data.phone', $this->patientA->phone);
        $sendRes->assertJsonPath('data.channels.whatsapp.recipient', $this->patientA->phone);
        $this->assertNotEmpty($sendRes->json('data.pdf_url'));
    }

    /** @test */
    public function it_requires_phone_for_whatsapp_and_allows_custom_phone_input()
    {
        Sanctum::actingAs($this->admin);

        $patientNoPhone = Patient::create([
            'first_name' => 'Jim',
            'last_name' => 'Halpert',
            'date_of_birth' => '1985-10-01',
            'gender' => 'male',
            'branch_id' => $this->branchA->id,
            'status' => 'Active',
        ]);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $patientNoPhone->id,
            'items' => [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Fails when whatsapp selected and no phone
        $failRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['whatsapp'],
        ]);
        $failRes->assertStatus(422);
        $failRes->assertJsonValidationErrors(['phone']);

        // Passes when phone is provided in request
        $passRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['whatsapp'],
            'phone' => '+1555999888',
        ]);
        $passRes->assertStatus(200);
        $passRes->assertJsonPath('data.phone', '+1555999888');
        $passRes->assertJsonPath('data.channels.whatsapp.recipient', '+1555999888');
        $passRes->assertJsonPath('data.channels.whatsapp.has_pdf', true);
        $this->assertEquals('+1555999888', $patientNoPhone->fresh()->phone);
    }

    /** @test */
    public function it_validates_and_dispatches_sms_with_text_only_and_no_pdf()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'X-Ray', 'quantity' => 1, 'unit_price' => 75]],
        ]);
        $quotationId = $createRes->json('data.id');

        $sendRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['sms'],
            'message' => 'Please review your quotation.',
        ]);

        $sendRes->assertStatus(200);
        $sendRes->assertJsonPath('data.channels.sms.success', true);
        $sendRes->assertJsonPath('data.channels.sms.has_pdf', false);
        $sendRes->assertJsonPath('data.telephone_number', $this->patientA->phone);
        $sendRes->assertJsonPath('data.channels.sms.recipient', $this->patientA->phone);
    }

    /** @test */
    public function it_requires_telephone_number_for_sms_and_allows_custom_telephone_number_input()
    {
        Sanctum::actingAs($this->admin);

        $patientNoPhone = Patient::create([
            'first_name' => 'Pam',
            'last_name' => 'Beesly',
            'date_of_birth' => '1986-03-25',
            'gender' => 'female',
            'branch_id' => $this->branchA->id,
            'status' => 'Active',
        ]);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $patientNoPhone->id,
            'items' => [['description' => 'Checkup', 'quantity' => 1, 'unit_price' => 60]],
        ]);
        $quotationId = $createRes->json('data.id');

        // Fails when SMS selected and no phone
        $failRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['sms'],
        ]);
        $failRes->assertStatus(422);
        $failRes->assertJsonValidationErrors(['telephone_number']);

        // Passes when telephone_number is provided in request
        $passRes = $this->postJson("/api/v1/quotations/{$quotationId}/send", [
            'channels' => ['sms'],
            'telephone_number' => '+1555777666',
        ]);
        $passRes->assertStatus(200);
        $passRes->assertJsonPath('data.telephone_number', '+1555777666');
        $passRes->assertJsonPath('data.channels.sms.recipient', '+1555777666');
        $passRes->assertJsonPath('data.channels.sms.has_pdf', false);
        $this->assertEquals('+1555777666', $patientNoPhone->fresh()->phone);
    }

    /** @test */
    public function it_downloads_pdf_through_public_token()
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/quotations', [
            'customer_type' => 'patient',
            'patient_id' => $this->patientA->id,
            'items' => [['description' => 'Veneer', 'quantity' => 2, 'unit_price' => 450]],
        ]);
        $quotationId = $createRes->json('data.id');

        $sendRes = $this->postJson("/api/v1/quotations/{$quotationId}/send");
        $token = $sendRes->json('data.token');

        $pdfRes = $this->get("/api/v1/public/quotations/{$token}/pdf");
        $pdfRes->assertStatus(200);
        $pdfRes->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdfRes->getContent());
    }
}
