<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main', 'address' => 'x']);
        $adminRole = RolePermission::create(['name' => 'Super Admin', 'permissions' => []]);
        $this->admin = Employee::factory()->create([
            'role' => 'Super Admin', 'role_id' => $adminRole->id, 'branch_id' => $this->branch->id,
        ]);
    }

    /** @test */
    public function super_admin_can_create_a_role_with_permissions(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/settings/roles', [
            'name' => 'Receptionist',
            'permissions' => ['patients:read', 'patients:read', 'quotations.view'],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(['patients:read', 'quotations.view'], RolePermission::where('name', 'Receptionist')->first()->permissions);
    }

    /** @test */
    public function create_role_rejects_malformed_permission_strings(): void
    {
        $this->actingAs($this->admin)->postJson('/api/settings/roles', [
            'name' => 'Bad',
            'permissions' => ['patients read; drop table'],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('role_permissions', ['name' => 'Bad']);
    }

    /** @test */
    public function permissions_can_be_added_and_removed_without_replacing_the_list(): void
    {
        $role = RolePermission::create(['name' => 'Dentist', 'permissions' => ['patients:read', 'charting:read']]);

        $this->actingAs($this->admin)->patchJson("/api/settings/roles/{$role->id}/permissions", [
            'add' => ['billing:read', 'patients:read'],
            'remove' => ['charting:read'],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['patients:read', 'billing:read'],
            $role->fresh()->permissions
        );
    }

    /** @test */
    public function permission_change_requires_add_or_remove(): void
    {
        $role = RolePermission::create(['name' => 'Dentist', 'permissions' => []]);

        $this->actingAs($this->admin)->patchJson("/api/settings/roles/{$role->id}/permissions", [])
            ->assertStatus(422);
    }

    /** @test */
    public function renaming_a_role_keeps_its_employees_permissions(): void
    {
        $role = RolePermission::create(['name' => 'Dentist', 'permissions' => ['charting:read']]);
        $employee = Employee::factory()->create([
            'role' => 'Dentist', 'role_id' => $role->id, 'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($this->admin)->patchJson("/api/settings/roles/{$role->id}", [
            'name' => 'Senior Dentist',
        ])->assertOk();

        $employee->refresh();
        $this->assertEquals('Senior Dentist', $employee->role);
        $this->assertTrue($employee->hasPermission('charting:read'));
    }

    /** @test */
    public function list_permissions_returns_distinct_strings_across_roles(): void
    {
        RolePermission::create(['name' => 'A', 'permissions' => ['patients:read', 'billing:read']]);
        RolePermission::create(['name' => 'B', 'permissions' => ['patients:read']]);

        $response = $this->actingAs($this->admin)->getJson('/api/settings/permissions');

        $response->assertOk();
        $this->assertContains('billing:read', $response->json('data'));
        $this->assertEquals(1, collect($response->json('data'))->filter(fn ($p) => $p === 'patients:read')->count());
    }

    /** @test */
    public function super_admin_role_permissions_cannot_be_changed(): void
    {
        $superAdminRole = RolePermission::where('name', 'Super Admin')->first();
        $before = $superAdminRole->permissions;

        $this->actingAs($this->admin)->putJson("/api/settings/roles/{$superAdminRole->id}", [
            'permissions' => ['patients:read'],
        ])->assertStatus(403);

        $this->actingAs($this->admin)->patchJson("/api/settings/roles/{$superAdminRole->id}/permissions", [
            'add' => ['billing:read'],
        ])->assertStatus(403);

        $this->actingAs($this->admin)->patchJson("/api/settings/roles/{$superAdminRole->id}/permissions", [
            'remove' => ['settings:manage'],
        ])->assertStatus(403);

        $this->assertEquals($before, $superAdminRole->fresh()->permissions);
    }

    /** @test */
    public function other_roles_permissions_can_still_be_changed(): void
    {
        $role = RolePermission::create(['name' => 'Dentist', 'permissions' => ['patients:read']]);

        $this->actingAs($this->admin)->putJson("/api/settings/roles/{$role->id}", [
            'permissions' => ['billing:read'],
        ])->assertOk();

        $this->assertEquals(['billing:read'], $role->fresh()->permissions);
    }

    /** @test */
    public function non_admins_cannot_change_roles(): void
    {
        $role = RolePermission::create(['name' => 'Dentist', 'permissions' => []]);
        $nurse = Employee::factory()->create([
            'role' => 'Receptionist', 'role_id' => $role->id, 'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($nurse)->postJson('/api/settings/roles', ['name' => 'X'])->assertStatus(403);
        $this->actingAs($nurse)->patchJson("/api/settings/roles/{$role->id}/permissions", ['add' => ['billing:read']])
            ->assertStatus(403);
    }
}
