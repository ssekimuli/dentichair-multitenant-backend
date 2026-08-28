<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LabService;
use App\Models\LabServicePrice;
use App\Models\User;
use Tests\TestCase;

class LabPricesTest extends TestCase
{
    protected $branch;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::first() ?? Branch::create(['name' => 'Test Branch', 'currency' => 'USD']);
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_can_list_lab_services()
    {
        $response = $this->actingAs($this->user)->getJson('/api/lab-services');
        $response->assertStatus(200);
        $response->assertIsArray();
    }

    public function test_can_create_lab_service()
    {
        $response = $this->actingAs($this->user)->postJson('/api/lab-services', [
            'service_name' => 'Test Crown',
            'category' => 'Prosthodontics',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'service_name', 'category', 'is_active']);
    }

    public function test_can_get_lab_service_by_id()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        $response = $this->actingAs($this->user)->getJson("/api/lab-services/{$service->id}");
        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $service->id]);
    }

    public function test_can_update_lab_service()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        $response = $this->actingAs($this->user)->putJson("/api/lab-services/{$service->id}", [
            'service_name' => 'Updated Crown',
            'category' => 'Prosthodontics',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['service_name' => 'Updated Crown']);
    }

    public function test_can_delete_lab_service()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        $response = $this->actingAs($this->user)->deleteJson("/api/lab-services/{$service->id}");
        $response->assertStatus(204);
    }

    public function test_can_create_lab_service_price()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        $response = $this->actingAs($this->user)->postJson('/api/lab-service-prices', [
            'lab_service_id' => $service->id,
            'lab' => 'Premier Lab',
            'base_price' => 5000,
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'lab_service_id', 'lab', 'base_price']);
    }

    public function test_can_update_lab_service_price()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        $price = LabServicePrice::factory()->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/lab-service-prices/{$price->id}", [
            'lab_service_id' => $service->id,
            'lab' => 'Standard Lab',
            'base_price' => 4000,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['base_price' => 4000]);
    }

    public function test_can_delete_lab_service_price()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        $price = LabServicePrice::factory()->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/lab-service-prices/{$price->id}");
        $response->assertStatus(204);
    }

    public function test_can_get_service_prices()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        LabServicePrice::factory()->count(3)->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/lab-services/{$service->id}/prices");
        $response->assertStatus(200);
    }

    public function test_can_get_active_price_for_service()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        LabServicePrice::factory()->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/lab-services/price-for-date', [
            'service_id' => $service->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['id', 'base_price']);
    }

    public function test_only_one_active_price_per_service()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);

        $price1 = $this->actingAs($this->user)->postJson('/api/lab-service-prices', [
            'lab_service_id' => $service->id,
            'lab' => 'Lab A',
            'base_price' => 5000,
            'is_active' => true,
        ]);

        $price2 = $this->actingAs($this->user)->postJson('/api/lab-service-prices', [
            'lab_service_id' => $service->id,
            'lab' => 'Lab B',
            'base_price' => 4000,
            'is_active' => true,
        ]);

        $this->assertEquals(1, LabServicePrice::where('lab_service_id', $service->id)->where('is_active', true)->count());
    }

    public function test_search_lab_services_by_name()
    {
        LabService::factory()->create([
            'branch_id' => $this->branch->id,
            'service_name' => 'Crown',
        ]);
        LabService::factory()->create([
            'branch_id' => $this->branch->id,
            'service_name' => 'Bridge',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/lab-services?search=crown');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_filter_lab_services_by_category()
    {
        LabService::factory()->create([
            'branch_id' => $this->branch->id,
            'service_name' => 'Crown',
            'category' => 'Prosthodontics',
        ]);
        LabService::factory()->create([
            'branch_id' => $this->branch->id,
            'service_name' => 'Implant',
            'category' => 'Implantology',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/lab-services?category=Prosthodontics');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_filter_lab_services_by_active_status()
    {
        LabService::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        LabService::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/lab-services?active=true');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_search_lab_prices_by_lab_name()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        LabServicePrice::factory()->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
            'lab' => 'Premier Lab',
        ]);
        LabServicePrice::factory()->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
            'lab' => 'Standard Lab',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/lab-service-prices?search=premier');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_filter_lab_prices_by_price_range()
    {
        $service = LabService::factory()->create(['branch_id' => $this->branch->id]);
        LabServicePrice::factory()->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
            'base_price' => 2000,
        ]);
        LabServicePrice::factory()->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
            'base_price' => 5000,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/lab-service-prices?min_price=3000&max_price=6000');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_filter_lab_prices_by_service_id()
    {
        $service1 = LabService::factory()->create(['branch_id' => $this->branch->id]);
        $service2 = LabService::factory()->create(['branch_id' => $this->branch->id]);

        LabServicePrice::factory()->create([
            'lab_service_id' => $service1->id,
            'branch_id' => $this->branch->id,
        ]);
        LabServicePrice::factory()->create([
            'lab_service_id' => $service2->id,
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/lab-service-prices?service_id={$service1->id}");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_combine_multiple_price_filters()
    {
        $service = LabService::factory()->create([
            'branch_id' => $this->branch->id,
            'service_name' => 'Crown',
        ]);
        LabServicePrice::factory()->create([
            'lab_service_id' => $service->id,
            'branch_id' => $this->branch->id,
            'lab' => 'Premier Lab',
            'base_price' => 5000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/lab-service-prices?search=crown&min_price=4000&max_price=6000&active=true');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }
}
