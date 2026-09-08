<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\LaboratoryOrder;
use App\Models\LabService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabOrderTest extends TestCase
{
    use RefreshDatabase;
    protected $branch;
    protected $user;
    protected $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::first() ?? Branch::create(['name' => 'Test Branch', 'address' => '123 Test St', 'currency' => 'USD']);
        $this->user = User::factory()->create();
        $this->client = Client::first() ?? Client::create([
            'name' => 'Test Lab Client',
            'email' => 'labclient@example.com',
            'phone' => '123456789'
        ]);
    }

    public function test_can_update_lab_order()
    {
        $labOrder = LaboratoryOrder::create([
            'client_id' => $this->client->id,
            'item' => 'Initial Item',
            'tooth' => 12,
            'status' => 'Draft',
            'sent_date' => '2026-09-01',
            'due_date' => '2026-09-05',
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/management/lab-orders/{$labOrder->id}", [
            'status' => 'In Production',
            'instructions' => 'Updated instructions test'
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'id' => $labOrder->id,
            'status' => 'In Production',
            'instructions' => 'Updated instructions test'
        ]);
    }

    public function test_can_update_lab_order_with_lab_service()
    {
        $service = LabService::first() ?? LabService::create([
            'service_name' => 'Test Service',
            'category' => 'Crown',
            'branch_id' => $this->branch->id,
            'is_active' => true
        ]);

        $labOrder = LaboratoryOrder::create([
            'client_id' => $this->client->id,
            'item' => 'Crown Item',
            'status' => 'Draft',
            'sent_date' => '2026-09-01',
            'due_date' => '2026-09-05',
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/management/lab-orders/{$labOrder->id}", [
            'labServiceId' => $service->id,
            'appliedPrice' => 250.00
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'id' => $labOrder->id,
            'lab_service_id' => $service->id,
            'applied_price' => "250.00"
        ]);
    }
}
