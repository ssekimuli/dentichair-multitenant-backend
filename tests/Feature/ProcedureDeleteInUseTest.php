<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\Treatment;
use Database\Seeders\InsuranceModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcedureDeleteInUseTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_procedure_used_by_insurance_rule_returns_409(): void
    {
        $role = RolePermission::firstOrCreate(['id' => 1], ['name' => 'Dentist', 'permissions' => []]);
        $branch = Branch::firstOrCreate(['id' => 1], [
            'name' => 'Downtown Clinic', 'address' => '123 Main St', 'currency' => 'KES',
        ]);
        $admin = Employee::factory()->create([
            'role_id' => $role->id, 'role' => $role->name, 'branch_id' => $branch->id,
        ]);

        // Seeds insurance coverage rules that reference these treatments
        (new InsuranceModuleSeeder())->run();
        $used = Treatment::where('treatment_code', 'D0120')->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/procedures/{$used->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This procedure is in use (for example by an insurance rule) and cannot be deleted.');

        $this->assertDatabaseHas('treatments', ['id' => $used->id]);
    }
}
