<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @group Employees
 * 
 * Endpoints for managing employee records, roles, and staff information.
 * Includes role-based access control and employee management.
 */
class EmployeeController extends Controller
{
    /**
     * Get All Employees
     * 
     * Retrieve a list of all employees with optional filtering by branch or role.
     * Returns complete employee details including branch information.
     * 
     * @authenticated
     * 
     * @queryParam branch_id string Filter employees by branch ID. Example: b1
     * @queryParam role string Filter employees by role. Example: Dentist
     * 
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "emp1",
     *       "name": "Dr. Lisa Cuddy",
     *       "avatar": "https://picsum.photos/seed/emp1/200/200",
     *       "email": "lisa.cuddy@example.com",
     *       "role": "Super Admin",
     *       "status": "Active",
     *       "salary": 150000.00,
     *       "hire_date": "2020-01-15",
     *       "branch_id": "b1",
     *       "branch": {
     *         "id": "b1",
     *         "name": "Downtown Clinic",
     *         "address": "123 Main St, Cityville"
     *       }
     *     }
     *   ]
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Employee::with(['branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        $employees = $query->get();

        return response()->json($employees);
    }

    /**
     * Create Employee
     * 
     * Create a new employee record with assigned role and branch.
     * Valid roles must be from the predefined role permissions.
     * 
     * @authenticated
     * 
     * @bodyParam name string required Employee's full name. Example: Dr. Sarah Johnson
     * @bodyParam email string required Employee's email address. Example: sarah.johnson@example.com
     * @bodyParam role string required Employee's role. Must be a valid role from role permissions. Example: Dentist
     * @bodyParam salary number required Employee's annual salary. Example: 120000.00
     * @bodyParam hire_date date required Employee's hire date (YYYY-MM-DD). Example: 2024-01-15
     * @bodyParam branch_id string required Branch ID where employee works. Example: b1
     * @bodyParam avatar string URL to employee's avatar image. Example: https://picsum.photos/200/200
     * 
     * @response 201 {
     *   "id": "emp6",
     *   "name": "Dr. Sarah Johnson",
     *   "avatar": "https://picsum.photos/200/200",
     *   "email": "sarah.johnson@example.com",
     *   "role": "Dentist",
     *   "status": "Active",
     *   "salary": 120000.00,
     *   "hire_date": "2024-01-15",
     *   "branch_id": "b1",
     *   "branch": {
     *     "id": "b1",
     *     "name": "Downtown Clinic",
     *     "address": "123 Main St, Cityville"
     *   }
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "name": ["The name field is required."],
     *     "email": ["The email field is required."],
     *     "role": ["The selected role is invalid."],
     *     "salary": ["The salary field is required."],
     *     "hire_date": ["The hire date field is required."],
     *     "branch_id": ["The branch id field is required."]
     *   }
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Get valid role IDs from role_permissions table
        $validRoleIds = RolePermission::pluck('id')->toArray();

        $validated = $request->validate([
            'name' => 'required|string',
            'avatar' => 'nullable|string',
            'email' => 'required|email|unique:employees,email',
            'role' => 'required|string', // Role name as string
            'role_id' => 'required|integer|in:' . implode(',', $validRoleIds), // role_id as integer
            'salary' => 'required|numeric',
            'hire_date' => 'required|date',
            'branch_id' => 'required|exists:branches,id',
            'password' => 'required|string|min:8',
        ]);

        $employee = Employee::create([
            ...$validated,
            'id' => 'emp' . (Employee::count() + 1),
            'status' => 'Active',
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json($employee->load('branch'), 201);
    }



    /**
     * Get Employee by ID
     * 
     * Retrieve detailed information for a specific employee including branch details.
     * 
     * @authenticated
     * 
     * @urlParam id string required The employee's ID. Example: emp1
     * 
     * @response 200 {
     *   "id": "emp1",
     *   "name": "Dr. Lisa Cuddy",
     *   "avatar": "https://picsum.photos/seed/emp1/200/200",
     *   "email": "lisa.cuddy@example.com",
     *   "role": "Super Admin",
     *   "status": "Active",
     *   "salary": 150000.00,
     *   "hire_date": "2020-01-15",
     *   "branch_id": "b1",
     *   "branch": {
     *     "id": "b1",
     *     "name": "Downtown Clinic",
     *     "address": "123 Main St, Cityville"
     *   }
     * }
     * @response 404 {
     *   "error": "Employee not found"
     * }
     * 
     * @param string $id Employee ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $employee = Employee::with(['branch'])->find($id);

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        return response()->json($employee);
    }

    /**
     * Update Employee
     * 
     * Update an existing employee's information including role changes.
     * Valid roles must be from the predefined role permissions.
     * 
     * @authenticated
     * 
     * @urlParam id string required The employee's ID. Example: emp1
     * @bodyParam name string Employee's full name. Example: Dr. Lisa Cuddy Updated
     * @bodyParam email string Employee's email address. Example: lisa.updated@example.com
     * @bodyParam role string Employee's role. Example: Dentist
     * @bodyParam status string Employee status. Example: On Leave
     * @bodyParam salary number Employee's annual salary. Example: 160000.00
     * @bodyParam hire_date date Employee's hire date. Example: 2020-01-15
     * @bodyParam branch_id string Branch ID. Example: b2
     * @bodyParam avatar string URL to employee's avatar image. Example: https://picsum.photos/200/200
     * 
     * @response 200 {
     *   "id": "emp1",
     *   "name": "Dr. Lisa Cuddy Updated",
     *   "avatar": "https://picsum.photos/200/200",
     *   "email": "lisa.updated@example.com",
     *   "role": "Dentist",
     *   "status": "On Leave",
     *   "salary": 160000.00,
     *   "hire_date": "2020-01-15",
     *   "branch_id": "b2",
     *   "branch": {
     *     "id": "b2",
     *     "name": "Uptown Dental",
     *     "address": "456 Oak Ave, Townburg"
     *   }
     * }
     * @response 404 {
     *   "error": "Employee not found"
     * }
     * 
     * @param Request $request
     * @param string $id Employee ID
     * @return \Illuminate\Http\JsonResponse
     */
   
    public function update(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        // Get valid role IDs from role_permissions table
        $validRoleIds = RolePermission::pluck('id')->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'avatar' => 'nullable|string',
            'email' => 'sometimes|email|unique:employees,email,' . $id,
            'role' => 'sometimes|string',
            'role_id' => 'sometimes|integer|in:' . implode(',', $validRoleIds),
            'salary' => 'sometimes|numeric',
            'hire_date' => 'sometimes|date',
            'branch_id' => 'sometimes|exists:branches,id',
            'status' => 'sometimes|string|in:Active,Inactive',
            'password' => 'nullable|string|min:8',
        ]);

        // Hash password if provided
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $employee->update($validated);

        return response()->json($employee->load('branch'));
    }

    /**
     * Delete Employee
     * 
     * Permanently delete an employee record from the system.
     * Use with caution as this action cannot be undone.
     * 
     * @authenticated
     * 
     * @urlParam id string required The employee's ID. Example: emp1
     * 
     * @response 200 {
     *   "success": true
     * }
     * @response 404 {
     *   "error": "Employee not found"
     * }
     * 
     * @param string $id Employee ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $employee = Employee::find($id);
        
        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        $employee->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get Available Roles
     * 
     * Retrieve a list of all available roles that can be assigned to employees.
     * Useful for populating role dropdowns in frontend applications.
     * 
     * @authenticated
     * 
     * @response 200 {
     *   "roles": [
     *     {
     *       "id": "Super Admin",
     *       "name": "Super Admin",
     *       "permissions": ["dashboard:read", "patients:read", "patients:create"]
     *     },
     *     {
     *       "id": "Dentist",
     *       "name": "Dentist",
     *       "permissions": ["dashboard:read", "patients:read", "charting:read"]
     *     },
     *     {
     *       "id": "Hygienist",
     *       "name": "Hygienist",
     *       "permissions": ["dashboard:read", "patients:read"]
     *     }
     *   ]
     * }
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRoles()
    {
        $roles = RolePermission::all();
        
        return response()->json(['roles' => $roles]);
    }
}