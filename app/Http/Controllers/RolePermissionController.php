<?php

namespace App\Http\Controllers;

use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

        $roles = RolePermission::all();
        
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

        $role->update([
            'permissions' => $request->permissions,
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

        $roles = RolePermission::select('id', 'name')->get();
        
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
}