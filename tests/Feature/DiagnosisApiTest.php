<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Employee;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiagnosisApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $user;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['name' => 'Main', 'address' => 'x']);
        $role = RolePermission::create(['name' => 'Dentist', 'permissions' => []]);
        $this->user = Employee::factory()->create([
            'role' => 'Dentist', 'role_id' => $role->id, 'branch_id' => $branch->id,
        ]);

        Diagnosis::create(['name' => 'Dental caries', 'category' => 'Dental Caries']);
        Diagnosis::create(['name' => 'Deep dental caries', 'category' => 'Dental Caries']);
        Diagnosis::create(['name' => 'Irreversible pulpitis', 'category' => 'Pulpal Diseases']);
        Diagnosis::create(['name' => 'Gingivitis', 'category' => 'Periodontal Diseases', 'icd_code' => 'K05.1']);
        Diagnosis::create(['name' => 'Old diagnosis', 'category' => 'Other Dental Conditions', 'is_active' => false]);
    }

    /** @test */
    public function it_returns_active_diagnoses_by_default(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/diagnoses');

        $response->assertOk();
        $this->assertEquals(4, $response->json('total'));
        $this->assertNotContains('Old diagnosis', collect($response->json('data'))->pluck('name')->all());
    }

    /** @test */
    public function it_searches_by_name_case_insensitively(): void
    {
        $names = collect($this->actingAs($this->user)->getJson('/api/diagnoses?q=CARIES')->json('data'))
            ->pluck('name')->sort()->values()->all();

        $this->assertEquals(['Deep dental caries', 'Dental caries'], $names);
    }

    /** @test */
    public function it_searches_by_icd_code(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/diagnoses?q=K05');

        $this->assertEquals(['Gingivitis'], collect($response->json('data'))->pluck('name')->all());
    }

    /** @test */
    public function search_treats_wildcard_characters_literally(): void
    {
        // A bare % must not match every row.
        $response = $this->actingAs($this->user)->getJson('/api/diagnoses?q=%25');

        $this->assertEquals(0, $response->json('total'));
    }

    /** @test */
    public function it_filters_by_category(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/diagnoses?category=Pulpal%20Diseases');

        $this->assertEquals(['Irreversible pulpitis'], collect($response->json('data'))->pluck('name')->all());
    }

    /** @test */
    public function it_can_return_inactive_diagnoses(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/diagnoses?is_active=0');

        $this->assertEquals(['Old diagnosis'], collect($response->json('data'))->pluck('name')->all());
    }

    /** @test */
    public function search_and_category_filters_combine(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/diagnoses?q=dental&category=Dental%20Caries');

        $this->assertEquals(2, $response->json('total'));
    }

    /** @test */
    public function it_paginates_results(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/diagnoses?per_page=2&page=2');

        $response->assertOk();
        $this->assertEquals(2, $response->json('current_page'));
        $this->assertEquals(2, $response->json('per_page'));
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(4, $response->json('total'));
    }

    /** @test */
    public function it_rejects_an_invalid_page_size(): void
    {
        $this->actingAs($this->user)->getJson('/api/diagnoses?per_page=500')->assertStatus(422);
    }

    /** @test */
    public function it_lists_categories_with_active_counts(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/diagnoses/categories');

        $response->assertOk();
        $counts = collect($response->json('data'))->pluck('count', 'category')->all();
        $this->assertEquals(2, $counts['Dental Caries']);
        $this->assertArrayNotHasKey('Other Dental Conditions', $counts);
    }

    /** @test */
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/diagnoses')->assertStatus(401);
    }
}
