<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Mirrors the payload the desktop billing screen sends to POST /api/invoices.
 */
class InvoiceCreateFromDesktopTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $role = RolePermission::create([
            'id' => 1,
            'name' => 'Dentist',
            'permissions' => [],
        ]);

        $this->branch = Branch::create([
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'USh',
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => $role->id,
            'role' => $role->name,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function desktopPayload(array $extra = []): array
    {
        return array_merge([
            'patient_name' => 'John Doe',
            'amount' => 350000,
            'issue_date' => '2026-11-18',
            'due_date' => '2026-12-18',
            'branch_id' => $this->branch->id,
            'category' => 'procedure-income',
            'treatments' => [
                [
                    'procedure' => 'Amalgam Filling',
                    'cost' => '150000.00',
                    'quantity' => 1,
                    'unit_price' => '150000.00',
                    'total_amount' => '150000.00',
                ],
                [
                    'procedure' => 'Composite Filling',
                    'cost' => '200000.00',
                    'quantity' => 1,
                    'unit_price' => '200000.00',
                    'total_amount' => '200000.00',
                ],
            ],
        ], $extra);
    }

    /** @test */
    public function it_creates_and_returns_a_patient_invoice_with_multiple_treatments()
    {
        $patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->employee)->postJson(
            '/api/invoices',
            $this->desktopPayload(['patient_id' => $patient->id])
        );

        $response->assertStatus(201);
        $response->assertJsonPath('patient_id', $patient->id);
        $response->assertJsonPath('branch_id', $this->branch->id);
        $response->assertJsonCount(2, 'treatments');

        $this->assertDatabaseHas('invoices', [
            'id' => $response->json('id'),
            'patient_id' => $patient->id,
            'branch_id' => $this->branch->id,
            'category' => 'procedure-income',
        ]);
    }

    /** @test */
    public function it_creates_an_invoice_for_a_client_and_stores_client_id()
    {
        $client = Client::create(['name' => 'Acme Labs']);

        $response = $this->actingAs($this->employee)->postJson(
            '/api/invoices',
            $this->desktopPayload([
                'patient_name' => 'Acme Labs',
                'client_id' => $client->id,
            ])
        );

        $response->assertStatus(201);
        $response->assertJsonPath('client_id', $client->id);
        $this->assertDatabaseHas('invoices', [
            'id' => $response->json('id'),
            'client_id' => $client->id,
            'patient_id' => null,
        ]);
    }

    /** @test */
    public function it_rejects_an_unknown_client_id()
    {
        $response = $this->actingAs($this->employee)->postJson(
            '/api/invoices',
            $this->desktopPayload(['client_id' => 999999])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id']);
        $this->assertDatabaseCount('invoices', 0);
    }

    /** @test */
    public function it_creates_a_client_invoice_when_no_settings_row_exists()
    {
        $client = Client::create(['name' => 'Acme Labs']);

        $response = $this->actingAs($this->employee)->postJson(
            '/api/invoices',
            $this->desktopPayload(['client_id' => $client->id])
        );

        $response->assertStatus(201);
    }
}
