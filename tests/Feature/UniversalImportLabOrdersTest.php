<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LaboratoryOrder;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalImportLabOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->branch = Branch::create([
            'name' => 'Main Dental Clinic',
            'address' => 'Kampala Road',
        ]);

        RolePermission::create([
            'id' => 1,
            'name' => 'Admin',
            'permissions' => ['manage_patients'],
        ]);

        $this->employee = Employee::create([
            'name' => 'Dr. Admin',
            'email' => 'admin@dentistchair.com',
            'role' => 'Admin',
            'role_id' => 1,
            'branch_id' => $this->branch->id,
            'salary' => 8000.00,
            'hire_date' => '2024-01-01',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);
    }

    /** @test */
    public function it_proposes_automatic_mapping_for_lab_orders_columns()
    {
        $csv = "CLIENT,ITEM,TOOTH,DOCTOR,TOTAL AMOUNT,PAID,STATUS,SENT DATE,DUE DATE\n";
        $csv .= "Apex Dental Lab,Ceramic Crown,14,Dr. Sarah,250000,100000,In Production,2026-08-01,2026-08-10\n";

        $file = UploadedFile::fake()->createWithContent('lab_orders_test.csv', $csv);

        $previewResponse = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', [
                'file' => $file,
                'table' => 'lab_orders',
            ]);

        $previewResponse->assertStatus(200);
        $mapping = $previewResponse->json('data.mapping');

        $this->assertEquals('client_name', $mapping['CLIENT']);
        $this->assertEquals('item', $mapping['ITEM']);
        $this->assertEquals('tooth', $mapping['TOOTH']);
        $this->assertEquals('doctor_name', $mapping['DOCTOR']);
        $this->assertTrue(in_array($mapping['TOTAL AMOUNT'], ['applied_price', 'total_amount'], true));
        $this->assertEquals('paid_amount', $mapping['PAID']);
        $this->assertEquals('status', $mapping['STATUS']);
        $this->assertEquals('sent_date', $mapping['SENT DATE']);
        $this->assertEquals('due_date', $mapping['DUE DATE']);

        $this->assertEmpty($previewResponse->json('data.unmapped_headers'));
        $this->assertEmpty($previewResponse->json('data.missing_required_fields'));
    }

    /** @test */
    public function it_links_existing_client_when_client_matches()
    {
        $client = Client::create([
            'name' => 'Crown & Bridge Dental Lab',
            'email' => 'lab@crownbridge.com',
            'phone' => '+256700555666',
            'address' => 'Plot 10 Industrial Area, Kampala',
        ]);

        $csv = "CLIENT,PHONE,ITEM,TOOTH,TOTAL AMOUNT,PAID,STATUS\n";
        $csv .= "Crown & Bridge Dental Lab,0700555666,Zirconia Crown,11,350000,350000,Ready for Delivery\n";

        $file = UploadedFile::fake()->createWithContent('lab_orders_match.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'table' => 'laboratory_orders',
                'file' => $file,
                'mode' => 'insert',
                'async' => false,
                'branch_id' => $this->branch->id,
                'mapping' => [
                    'CLIENT' => 'client_name',
                    'PHONE' => 'client_phone',
                    'ITEM' => 'item',
                    'TOOTH' => 'tooth',
                    'TOTAL AMOUNT' => 'total_amount',
                    'PAID' => 'paid_amount',
                    'STATUS' => 'status',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        // No new client created
        $this->assertEquals(1, Client::count());

        $order = LaboratoryOrder::first();
        $this->assertNotNull($order);
        $this->assertEquals($client->id, $order->client_id);
        $this->assertEquals('Zirconia Crown', $order->item);
        $this->assertEquals(11, $order->tooth);
        $this->assertEquals('Ready for Delivery', $order->status);
        $this->assertEquals(350000.00, (float)$order->applied_price);

        // Verify linked invoice
        $this->assertNotNull($order->invoice_id);
        $invoice = Invoice::find($order->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertEquals('Crown & Bridge Dental Lab', $invoice->patient_name);
        $this->assertNull($invoice->patient_id);
        $this->assertEquals(350000.00, (float)$invoice->amount);
        $this->assertEquals(350000.00, (float)$invoice->paid_amount);
        $this->assertEquals(0.00, (float)$invoice->patient_balance);
        $this->assertEquals('Paid', $invoice->status);
        $this->assertEquals('lab-income', $invoice->category);
    }

    /** @test */
    public function it_auto_creates_client_when_client_does_not_exist()
    {
        $this->assertEquals(0, Client::count());

        $csv = "CLIENT,PHONE,EMAIL,ITEM,TOOTH,TOTAL AMOUNT,PAID\n";
        $csv .= "Pearl Precision Lab,0788123456,contact@pearllab.com,Full Ceramic Crown,21,300000,100000\n";

        $file = UploadedFile::fake()->createWithContent('lab_orders_new_client.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'table' => 'lab_order',
                'file' => $file,
                'mode' => 'insert',
                'async' => false,
                'branch_id' => $this->branch->id,
                'mapping' => [
                    'CLIENT' => 'client_name',
                    'PHONE' => 'client_phone',
                    'EMAIL' => 'client_email',
                    'ITEM' => 'item',
                    'TOOTH' => 'tooth',
                    'TOTAL AMOUNT' => 'total_amount',
                    'PAID' => 'paid_amount',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        // Client was created
        $this->assertEquals(1, Client::count());
        $createdClient = Client::where('name', 'Pearl Precision Lab')->first();
        $this->assertNotNull($createdClient);
        $this->assertEquals('0788123456', $createdClient->phone);
        $this->assertEquals('contact@pearllab.com', $createdClient->email);

        // Lab order linked to new client
        $order = LaboratoryOrder::first();
        $this->assertNotNull($order);
        $this->assertEquals($createdClient->id, $order->client_id);
        $this->assertEquals(300000.00, (float)$order->applied_price);

        // Invoice created with Pending status because balance remains
        $invoice = Invoice::find($order->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertEquals('Pearl Precision Lab', $invoice->patient_name);
        $this->assertEquals(300000.00, (float)$invoice->amount);
        $this->assertEquals(100000.00, (float)$invoice->paid_amount);
        $this->assertEquals(200000.00, (float)$invoice->patient_balance);
        $this->assertEquals('Pending', $invoice->status);
        $this->assertEquals('lab-income', $invoice->category);
    }

    /** @test */
    public function it_tracks_total_amount_and_paid_amount_with_pending_status_when_balance_remains()
    {
        $csv = "Item,Price,Amount Paid,Dental Lab\n";
        $csv .= "Acrylic Denture,500000,200000,Modern Denture Tech\n";

        $file = UploadedFile::fake()->createWithContent('lab_orders_balance.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'table' => 'lab_orders',
                'file' => $file,
                'mode' => 'insert',
                'async' => false,
                'branch_id' => $this->branch->id,
                'mapping' => [
                    'Item' => 'item',
                    'Price' => 'applied_price',
                    'Amount Paid' => 'paid_amount',
                    'Dental Lab' => 'client_name',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        $order = LaboratoryOrder::first();
        $this->assertNotNull($order);
        $this->assertEquals(500000.00, (float)$order->applied_price);

        $invoice = Invoice::find($order->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertEquals(500000.00, (float)$invoice->amount);
        $this->assertEquals(200000.00, (float)$invoice->paid_amount);
        $this->assertEquals(300000.00, (float)$invoice->patient_balance);
        $this->assertEquals('Pending', $invoice->status);
    }

    /** @test */
    public function it_tracks_total_amount_and_paid_amount_with_paid_status_when_fully_paid()
    {
        $csv = "Item,Price,Amount Paid,Dental Lab\n";
        $csv .= "Flexible Partial Denture,350000,350000,Modern Denture Tech\n";

        $file = UploadedFile::fake()->createWithContent('lab_orders_paid.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'table' => 'lab_orders',
                'file' => $file,
                'mode' => 'insert',
                'async' => false,
                'branch_id' => $this->branch->id,
                'mapping' => [
                    'Item' => 'item',
                    'Price' => 'applied_price',
                    'Amount Paid' => 'paid_amount',
                    'Dental Lab' => 'client_name',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        $order = LaboratoryOrder::first();
        $this->assertNotNull($order);
        $this->assertEquals(350000.00, (float)$order->applied_price);

        $invoice = Invoice::find($order->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertEquals(350000.00, (float)$invoice->amount);
        $this->assertEquals(350000.00, (float)$invoice->paid_amount);
        $this->assertEquals(0.00, (float)$invoice->patient_balance);
        $this->assertEquals('Paid', $invoice->status);
    }

    /** @test */
    public function it_normalizes_status_to_valid_enum_and_extracts_tooth_and_patient()
    {
        $csv = "Item,Tooth,Client,Status,Patient,Total,Paid\n";
        $csv .= "Bridge 3-Unit,14-16,Apex Lab,in production,Alice Doe,600000,600000\n";

        $file = UploadedFile::fake()->createWithContent('lab_orders_enum.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'table' => 'laboratory_orders',
                'file' => $file,
                'mode' => 'insert',
                'async' => false,
                'branch_id' => $this->branch->id,
                'mapping' => [
                    'Item' => 'item',
                    'Tooth' => 'tooth',
                    'Client' => 'client_name',
                    'Status' => 'status',
                    'Patient' => 'patient_name',
                    'Total' => 'total_amount',
                    'Paid' => 'paid_amount',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        $order = LaboratoryOrder::first();
        $this->assertNotNull($order);
        $this->assertEquals('In Production', $order->status);
        $this->assertEquals(14, $order->tooth);
        $this->assertStringContainsString('Patient: Alice Doe', $order->instructions);
        $this->assertStringContainsString('Tooth: 14-16', $order->instructions);
    }
}
