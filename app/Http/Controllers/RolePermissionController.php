<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * @group Roles & Permissions Management
 * 
 * Endpoints for managing user roles and permissions in the Dental Management System.
 * Super Admin access required for most operations.
 */
class RolePermissionController extends Controller
{
    /**
     * Get All Roles and Permissions
     * 
     * Retrieve a comprehensive list of all defined roles and their assigned permissions.
     * Intended for the 'Roles & Permissions' settings page.
     * 
     * @authenticated
     * @response 200 {
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Super Admin",
     *       "permissions": [
     *         "dashboard:read",
     *         "queue:read",
     *         "patients:read",
     *         "patients:create",
     *         "patients:update",
     *         "patients:delete",
     *         "settings:manage"
     *       ]
     *     },
     *     {
     *       "id": 2,
     *       "name": "Dentist",
     *       "permissions": [
     *         "dashboard:read",
     *         "queue:read",
     *         "patients:read",
     *         "charting:read",
     *         "charting:update"
     *       ]
     *     }
     *   ]
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * @response 403 {
     *   "message": "Unauthorized. Super Admin access required."
     * }
     * 
     * @return JsonResponse
     */
    public function getAllRolesWithPermissions(): JsonResponse
    {
        // Check if user has Super Admin role
        if (!auth()->user()->hasRole('Super Admin')) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $roles = RolePermission::latest()->get();
        
        return response()->json([
            'data' => $roles
        ]);
    }

    /**
     * Update Permissions for a Role
     * 
     * Update the permissions for a specific role. The provided permissions array 
     * will completely replace the existing permissions for that role.
     * 
     * @authenticated
     * @urlParam role_id integer required The ID of the role to update. Example: 1
     * @bodyParam permissions array required Array of permission strings. Example: ["dashboard:read", "patients:read"]
     * 
     * @response 200 {
     *   "message": "Permissions updated successfully",
     *   "data": {
     *     "id": 1,
     *     "name": "Super Admin",
     *     "permissions": [
     *       "dashboard:read",
     *       "patients:read",
     *       "patients:create"
     *     ]
     *   }
     * }
     * @response 404 {
     *   "message": "Role not found"
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "permissions": ["The permissions field is required."]
     *   }
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * @response 403 {
     *   "message": "Unauthorized. Super Admin access required."
     * }
     * 
     * @param Request $request
     * @param int $role_id
     * @return JsonResponse
     */
    public function updateRolePermissions(Request $request, int $role_id): JsonResponse
    {
        // Check if user has Super Admin role
        if (!auth()->user()->hasRole('Super Admin')) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        $role = RolePermission::find($role_id);

        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        if ($locked = self::protectedRoleViolation($role)) {
            return $locked;
        }

        $role->update([
            'permissions' => self::normalizePermissions($request->permissions),
        ]);

        return response()->json([
            'message' => 'Permissions updated successfully',
            'data' => $role
        ]);
    }

    /**
     * Get Simple List of Roles
     * 
     * Retrieve a lightweight list of all roles containing only their ID and name.
     * Useful for populating UI dropdowns when creating or editing employees.
     * 
     * @authenticated
     * @response 200 {
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Super Admin"
     *     },
     *     {
     *       "id": 2,
     *       "name": "Accountant"
     *     },
     *     {
     *       "id": 3,
     *       "name": "HR Manager"
     *     },
     *     {
     *       "id": 4,
     *       "name": "Dentist"
     *     },
     *     {
     *       "id": 5,
     *       "name": "Hygienist"
     *     },
     *     {
     *       "id": 6,
     *       "name": "Receptionist"
     *     },
     *     {
     *       "id": 7,
     *       "name": "Dental Assistant"
     *     }
     *   ]
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * @response 403 {
     *   "message": "Unauthorized. Super Admin or HR access required."
     * }
     * 
     * @return JsonResponse
     */
    public function getRolesList(): JsonResponse
    {
        // Check if user has Super Admin or HR role
        $user = auth()->user();
        if (!$user->hasRole('Super Admin') && !$user->hasRole('HR Manager')) {
            return response()->json(['message' => 'Unauthorized. Super Admin or HR access required.'], 403);
        }

        $roles = RolePermission::select('id', 'name')->latest()->get();
        
        return response()->json([
            'data' => $roles
        ]);
    }

    /**
     * Get Role by ID
     * 
     * Retrieve detailed information about a specific role including all permissions.
     * 
     * @authenticated
     * @urlParam role_id integer required The ID of the role to retrieve. Example: 1
     * 
     * @response 200 {
     *   "data": {
     *     "id": 1,
     *     "name": "Super Admin",
     *     "permissions": [
     *       "dashboard:read",
     *       "queue:read",
     *       "patients:read",
     *       "patients:create",
     *       "patients:update",
     *       "patients:delete",
     *       "settings:manage"
     *     ]
     *   }
     * }
     * @response 404 {
     *   "message": "Role not found"
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * @response 403 {
     *   "message": "Unauthorized. Super Admin access required."
     * }
     * 
     * @param int $role_id
     * @return JsonResponse
     */
    public function getRoleById(int $role_id): JsonResponse
    {
        // Check if user has Super Admin role
        if (!auth()->user()->hasRole('Super Admin')) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $role = RolePermission::find($role_id);

        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        return response()->json([
            'data' => $role
        ]);
    }

    public function createRole(Request $request): JsonResponse
    {
        // Check if user has Super Admin role
        if (!auth()->user()->hasRole('Super Admin')) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|unique:role_permissions,name|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => self::PERMISSION_RULE,
        ]);

        $role = RolePermission::create([
            'name' => $validated['name'],
            'permissions' => self::normalizePermissions($validated['permissions'] ?? []),
        ]);

        return response()->json($role, 201);
    }

    /**
     * Add and/or remove individual permissions on a role without replacing the whole list.
     *
     * @param Request $request
     * @param int $role_id
     * @return JsonResponse
     */
    public function changeRolePermissions(Request $request, int $role_id): JsonResponse
    {
        // Check if user has Super Admin role
        if (!auth()->user()->hasRole('Super Admin')) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $validated = $request->validate([
            'add' => 'nullable|array|required_without:remove',
            'add.*' => self::PERMISSION_RULE,
            'remove' => 'nullable|array|required_without:add',
            'remove.*' => 'string|max:100',
        ]);

        $role = RolePermission::find($role_id);

        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        if ($locked = self::protectedRoleViolation($role)) {
            return $locked;
        }

        $current = $role->permissions ?? [];
        $remaining = array_diff($current, $validated['remove'] ?? []);

        $role->update([
            'permissions' => self::normalizePermissions(array_merge($remaining, $validated['add'] ?? [])),
        ]);

        return response()->json([
            'message' => 'Permissions updated successfully',
            'data' => $role,
        ]);
    }

    /**
     * List every permission string currently used by any role.
     *
     * @return JsonResponse
     */
    public function listPermissions(): JsonResponse
    {
        // Check if user has Super Admin role
        if (!auth()->user()->hasRole('Super Admin')) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $permissions = RolePermission::pluck('permissions')
            ->flatten()
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return response()->json(['data' => $permissions]);
    }

    /**
     * Permission strings look like "patients:read" or "quotations.view".
     */
    private const PERMISSION_RULE = ['string', 'max:100', 'regex:/^[a-z_]+[:.][a-z_]+$/'];

    /**
     * The Super Admin role is the one that manages everything else.
     * Its permissions cannot be changed through the API.
     */
    private const PROTECTED_ROLE = 'Super Admin';

    private static function normalizePermissions(array $permissions): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $permissions))));
    }

    /**
     * Return a 403 response if the role's permissions are locked.
     */
    private static function protectedRoleViolation(RolePermission $role): ?JsonResponse
    {
        if ($role->name !== self::PROTECTED_ROLE) {
            return null;
        }

        return response()->json([
            'message' => 'The Super Admin role\'s permissions cannot be changed.',
        ], 403);
    }

    public function updateRoleName(Request $request, int $role_id): JsonResponse
    {
        // Check if user has Super Admin role
        if (!auth()->user()->hasRole('Super Admin')) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|unique:role_permissions,name,' . $role_id . '|max:255',
        ]);

        $role = RolePermission::find($role_id);

        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        $oldName = $role->name;

        DB::transaction(function () use ($role, $validated, $oldName) {
            $role->update([
                'name' => $validated['name'],
            ]);

            // Employees store their role by name, so keep them attached to the renamed role.
            if ($oldName !== $validated['name']) {
                Employee::where('role', $oldName)->update(['role' => $validated['name']]);
            }
        });

        return response()->json($role);
    }

    public function deleteRole(int $role_id): JsonResponse
    {
        // Check if user has Super Admin role
        if (!auth()->user()->hasRole('Super Admin')) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $role = RolePermission::find($role_id);

        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        $role->delete();

        return response()->json(['success' => true]);
    }
}