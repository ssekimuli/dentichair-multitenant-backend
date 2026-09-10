<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\LaboratoryOrder;
use App\Models\LabService;
use Tests\TestCase;

class LabOrderBatchTest extends TestCase
{
    private $employee;
    private $client;
    private $branch;
    private $labService;
    private array $createdOrderIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::first() ?? Branch::create(['name' => 'Test Branch', 'currency' => 'UGX']);
        $this->client = Client::first() ?? Client::create(['name' => 'Test Client']);
        $this->labService = LabService::first() ?? LabService::create(['service_name' => 'Test Service', 'branch_id' => $this->branch->id]);
        $this->employee = Employee::first();
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdOrderIds)) {
            LaboratoryOrder::whereIn('id', $this->createdOrderIds)->delete();
        }
        parent::tearDown();
    }

    public function test_can_create_batch_lab_orders_with_exact_payload()
    {
        if (!$this->employee) {
            $this->markTestSkipped('No employee found for authentication');
        }

        $payload = [
            'branchId' => $this->branch->id,
            'clientId' => (string) $this->client->id,
            'doctorName' => 'Ande',
            'status' => 'Draft',
            'sentDate' => '2026-09-10',
            'dueDate' => '2026-09-10',
            'instructions' => 'vv',
            'items' => [
                [
                    'item' => 'wew',
                    'tooth' => 21,
                    'lab_service_id' => $this->labService->id,
                    'applied_price' => 3500,
                    'material' => 'ewe',
                    'shade' => '32',
                ]
            ],
            'receivedDate' => '2026-09-10',
        ];

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/lab-orders/batch', $payload);

        $response->assertStatus(201);
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'item' => 'wew',
            'tooth' => 21,
            'status' => 'Draft',
            'doctor_name' => 'Ande',
            'instructions' => 'vv',
        ]);

        $data = $response->json();
        $this->createdOrderIds[] = $data[0]['id'];
    }

    public function test_can_create_batch_lab_orders_with_multiple_items()
    {
        if (!$this->employee) {
            $this->markTestSkipped('No employee found for authentication');
        }

        $payload = [
            'branchId' => $this->branch->id,
            'clientId' => $this->client->id,
            'doctorName' => 'Dr. Smith',
            'status' => 'Draft',
            'sentDate' => '2026-09-10',
            'dueDate' => '2026-09-15',
            'instructions' => 'Common instruction',
            'items' => [
                [
                    'item' => 'Crown',
                    'tooth' => 11,
                    'lab_service_id' => $this->labService->id,
                    'applied_price' => 4000,
                    'material' => 'Zirconia',
                    'shade' => 'A1',
                ],
                [
                    'item' => 'Bridge',
                    'tooth' => 12,
                    'lab_service_id' => $this->labService->id,
                    'applied_price' => 8000,
                    'material' => 'Porcelain',
                    'shade' => 'A2',
                ]
            ],
            'receivedDate' => '2026-09-10',
        ];

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/lab-orders/batch', $payload);

        $response->assertStatus(201);
        $response->assertJsonCount(2);

        $data = $response->json();
        foreach ($data as $order) {
            $this->createdOrderIds[] = $order['id'];
        }
    }

    public function test_batch_validation_fails_without_required_fields()
    {
        if (!$this->employee) {
            $this->markTestSkipped('No employee found for authentication');
        }

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/lab-orders/batch', [
                'status' => 'Draft',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['clientId', 'sentDate', 'dueDate', 'items']);
    }
}
