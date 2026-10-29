<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = RolePermission::create([
            'id' => 1,
            'name' => 'Admin',
            'permissions' => []
        ]);

        $this->branch = Branch::create([
            'name' => 'Main Clinic',
            'address' => '456 Healthcare Blvd',
            'currency' => 'USD'
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id
        ]);
    }

    /** @test */
    public function it_creates_a_new_expense_category_when_category_is_sent_as_a_string()
    {
        $payload = [
            'branchId' => $this->branch->id,
            'date' => '2026-10-29',
            'category' => 'Dental Supplies',
            'description' => 'Gloves and masks order',
            'amount' => 250.75,
        ];

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/expenses', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('category', 'Dental Supplies');

        // Check category was created in expense_categories table
        $this->assertDatabaseHas('expense_categories', [
            'name' => 'Dental Supplies',
            'branch_id' => $this->branch->id,
        ]);

        $category = ExpenseCategory::where('name', 'Dental Supplies')->first();
        $this->assertNotNull($category);

        $response->assertJsonPath('expense_category_id', $category->id);

        // Check expense was saved with both category name and ID
        $this->assertDatabaseHas('expenses', [
            'category' => 'Dental Supplies',
            'expense_category_id' => $category->id,
            'amount' => 250.75,
        ]);
    }

    /** @test */
    public function it_reuses_existing_category_when_same_string_is_sent()
    {
        $category = ExpenseCategory::create([
            'name' => 'Electricity',
            'branch_id' => $this->branch->id,
        ]);

        $payload = [
            'branchId' => $this->branch->id,
            'date' => '2026-10-29',
            'category' => 'Electricity',
            'description' => 'Power bill',
            'amount' => 100.00,
        ];

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/expenses', $payload);

        $response->assertStatus(201);
        $this->assertEquals(1, ExpenseCategory::where('name', 'Electricity')->count());
        $response->assertJsonPath('expense_category_id', $category->id);
    }

    /** @test */
    public function it_attaches_category_when_sent_as_an_id()
    {
        $category = ExpenseCategory::create([
            'name' => 'Marketing',
            'branch_id' => $this->branch->id,
        ]);

        $payload = [
            'branchId' => $this->branch->id,
            'date' => '2026-10-29',
            'category' => $category->id,
            'description' => 'Social media ads',
            'amount' => 120.00,
        ];

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/expenses', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('category', 'Marketing');
        $response->assertJsonPath('expense_category_id', $category->id);

        $this->assertDatabaseHas('expenses', [
            'category' => 'Marketing',
            'expense_category_id' => $category->id,
            'amount' => 120.00,
        ]);
    }

    /** @test */
    public function it_supports_category_id_field_name_in_payload()
    {
        $category = ExpenseCategory::create([
            'name' => 'Software Licenses',
            'branch_id' => $this->branch->id,
        ]);

        $payload = [
            'branchId' => $this->branch->id,
            'date' => '2026-10-29',
            'category_id' => $category->id,
            'description' => 'Practice Management Software',
            'amount' => 300.00,
        ];

        $response = $this->actingAs($this->employee)
            ->postJson('/api/management/expenses', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('category', 'Software Licenses');
        $response->assertJsonPath('expense_category_id', $category->id);
    }

    /** @test */
    public function it_can_update_expense_with_new_category_string_or_id()
    {
        $expense = Expense::create([
            'branch_id' => $this->branch->id,
            'date' => '2026-10-29',
            'category' => 'Old Category',
            'description' => 'Initial expense',
            'amount' => 50.00,
        ]);

        // Update with new string category
        $response = $this->actingAs($this->employee)
            ->patchJson("/api/management/expenses/{$expense->id}", [
                'category' => 'Office Renovation',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('category', 'Office Renovation');

        $newCategory = ExpenseCategory::where('name', 'Office Renovation')->first();
        $this->assertNotNull($newCategory);
        $this->assertEquals($newCategory->id, $response->json('expense_category_id'));
    }

    /** @test */
    public function it_lists_expense_categories()
    {
        ExpenseCategory::create(['name' => 'Advertising', 'branch_id' => $this->branch->id]);
        ExpenseCategory::create(['name' => 'Lab Fees', 'branch_id' => $this->branch->id]);

        $response = $this->actingAs($this->employee)
            ->getJson("/api/management/expense-categories?branch_id={$this->branch->id}");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }
}

