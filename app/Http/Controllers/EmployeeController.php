<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmployeeWelcomeMail;

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

        $employees = $query->latest()->get();

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
        if (!$request->has('role') && $request->has('role_id')) {
            $request->merge(['role' => $request->role_id]);
        } elseif ($request->has('role') && !$request->has('role_id')) {
            $request->merge(['role_id' => $request->role]);
        }

        $validated = $request->validate([
            'name' => 'required|string',
            'avatar' => 'nullable|string',
            'email' => 'required|email|unique:employees,email',
            'role' => 'required|exists:role_permissions,id',
            'salary' => 'required|numeric',
            'hire_date' => 'required|date',
            'branch_id' => 'required|exists:branches,id',
        ]);

        // Fetch the role name automatically
        $role = RolePermission::find($validated['role']);
        
        $temporaryPassword = Str::password(6, true, true, false, false);

        $employee = Employee::create([
            'name' => $validated['name'],
            'avatar' => $validated['avatar'] ?? null,
            'email' => $validated['email'],
            'role_id' => $validated['role'],
            'role' => $role->name ?? 'Unknown',
            'salary' => $validated['salary'],
            'hire_date' => $validated['hire_date'],
            'branch_id' => $validated['branch_id'],
            'status' => 'Active',
            'password' => Hash::make($temporaryPassword),
        ]);

        $employee->load('branch');

        $settings = \App\Models\GlobalSetting::getSettings();

        if ($settings && isset($settings->notifications['staff_created'])) {
            $sendEmail = $settings->notifications['staff_created']['email'] ?? true;
            $sendSms = $settings->notifications['staff_created']['sms'] ?? false;
            $sendWa = $settings->notifications['staff_created']['whatsapp'] ?? false;
            $clinicName = $settings->clinic_name ?? 'Our Clinic';

            if ($sendSms && isset($employee->phone) && $settings->deductSms()) {
                $msg = "Welcome {$employee->name}! You have been added as {$employee->role} at {$clinicName}. Your temporary password is: {$temporaryPassword}";
                \App\Services\NotificationService::sendSms($employee->phone, $msg);
            }

            if ($sendWa && isset($employee->phone) && $settings->isWhatsappActive()) {
                $msg = "Welcome {$employee->name}! You have been added as {$employee->role} at {$clinicName}. Your temporary password is: {$temporaryPassword}";
                \App\Services\NotificationService::sendWhatsApp($employee->phone, $msg);
            }
        }

        $sendEmail = $settings->notifications['staff_created']['email'] ?? true;
        $clinicName = $settings->clinic_name ?? 'Our Clinic';

        if ($sendEmail && $employee->email) {
            try {
                Mail::to($employee->email)->send(new EmployeeWelcomeMail([
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'temporaryPassword' => $temporaryPassword,
                    'role' => $employee->role,
                    'branchName' => $employee->branch->name ?? 'Dental Clinic',
                    'clinicName' => $clinicName
                ], 'Welcome to ' . $clinicName));
            } catch (\Exception $e) {
                logger()->error("Failed to send employee welcome email: " . $e->getMessage());
            }
        }

        return response()->json($employee, 201);
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
        $employee = Employee::with(['branch', 'payrolls', 'leaveRequests', 'staffAdvances'])->find($id);

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        // === Compute Stats ===

        // Total amount paid from payrolls
        $totalPaid = $employee->payrolls->sum('amount') ?? 0;

        // Attendance stats
        $presentDays = $employee->attendance()->where('status', 'present')->count() ?? 0;
        $absentDays = $employee->attendance()->where('status', 'absent')->count() ?? 0;

        // ✅ Calculate leave days dynamically (Approved requests only)
        $onLeaveDays = $employee->leaveRequests()
            ->where('status', 'Approved') // match your table case
            ->get()
            ->sum(function ($leave) {
                $start = Carbon::parse($leave->start_date);
                $end = Carbon::parse($leave->end_date);
                return $start->diffInDays($end) + 1; // inclusive of start and end date
            });

        // Appointment stats (if applicable)
        $totalAppointments = $employee->appointments()->count() ?? 0;
        $uniquePatients = $employee->appointments()->distinct('patient_id')->count('patient_id') ?? 0;
        $completedAppointments = $employee->appointments()->where('status', 'completed')->count() ?? 0;
        $pendingAppointments = $employee->appointments()->where('status', 'pending')->count() ?? 0;

        // Derived metric
        $patientsTreated = $completedAppointments;

        // === Merge stats into response ===
        $employeeData = $employee->toArray();
        $employeeData['stats'] = [
            'totalPaid' => $totalPaid,
            'presentDays' => $presentDays,
            'absentDays' => $absentDays,
            'onLeaveDays' => $onLeaveDays,
            'totalAppointments' => $totalAppointments,
            'uniquePatients' => $uniquePatients,
            'completedAppointments' => $completedAppointments,
            'pendingAppointments' => $pendingAppointments,
            'patientsTreated' => $patientsTreated,
        ];

        return response()->json($employeeData);
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

        $hasRoleChange = false;
        if ($request->has('role_id') && $request->input('role_id') != $employee->role_id) {
            $hasRoleChange = true;
        }

        if ($hasRoleChange) {
            $user = $request->user();
            if (!$user || $user->role !== 'Super Admin') {
                return response()->json([
                    'message' => 'Role modifications are restricted',
                    'errors' => [
                        'role_id' => ['Role modifications are restricted']
                    ]
                ], 422);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'avatar' => 'nullable|string',
            'email' => 'sometimes|email|unique:employees,email,' . $id,
            'role_id' => 'nullable|exists:role_permissions,id',
            'salary' => 'sometimes|numeric',
            'hire_date' => 'sometimes|date',
            'branch_id' => 'sometimes|exists:branches,id',
            'status' => 'sometimes|string|in:Active,Inactive',
        ]);

        // Auto-fetch role name if provided
        if (isset($validated['role_id'])) {
            $role = RolePermission::find($validated['role_id']);
            $validated['role'] = $role->name ?? $employee->role;
        }

        // Hash password if provided
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make('DE' . rand(1000, 9999));
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

    /**
     * Get All Doctors
     * 
     * Retrieve a list of all doctors (dentists and medical practitioners) with optional filtering.
     * Includes doctors with roles like Dentist, Doctor, Orthodontist, etc.
     * 
     * @authenticated
     * 
     * @queryParam branch_id string Filter doctors by branch ID. Example: b1
     * 
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "emp2",
     *       "name": "Dr. Gregory House",
     *       "avatar": "https://picsum.photos/seed/emp2/200/200",
     *       "email": "gregory.house@example.com",
     *       "role": "Dentist",
     *       "status": "Active",
     *       "salary": 120000.00,
     *       "hire_date": "2021-03-10",
     *       "branch_id": "b1",
     *       "branch": {
     *         "id": "b1",
     *         "name": "Downtown Clinic",
     *         "address": "123 Main St, Cityville"
     *       }
     *     },
     *     {
     *       "id": "emp3",
     *       "name": "Dr. Allison Cameron",
     *       "avatar": "https://picsum.photos/seed/emp3/200/200",
     *       "email": "allison.cameron@example.com",
     *       "role": "Orthodontist",
     *       "status": "Active",
     *       "salary": 110000.00,
     *       "hire_date": "2022-05-20",
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
    /**
     * Resend Employee Password
     * 
     * Resets the employee's password and sends an email with the new credentials.
     * 
     * @authenticated
     * 
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendPassword($id)
    {
        $employee = Employee::findOrFail($id);
        
        // Generate a random temporary password
        $temporaryPassword = Str::password(6, true, true, false, false);
        
        $employee->update(['password' => Hash::make($temporaryPassword)]);
        $employee->load('branch');

        if ($employee->email) {
            try {
                Mail::to($employee->email)->send(new EmployeeWelcomeMail([
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'temporaryPassword' => $temporaryPassword,
                    'role' => $employee->role,
                    'branchName' => $employee->branch->name ?? 'Dental Clinic',
                ], 'Your Password Has Been Reset - Dental Management System'));
                
                return response()->json(['message' => 'Password resent successfully']);
            } catch (\Exception $e) {
                logger()->error("Failed to send employee password email: " . $e->getMessage());
                return response()->json(['message' => 'Password was reset, but email failed to send.']);
            }
        }
        
        return response()->json(['error' => 'Employee has no email address'], 400);
    }

    /**
     * Reset Employee Password by Email
     * 
     * Resets the employee's password via their email and sends an email with the new credentials.
     * 
     * @authenticated
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPasswordByEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:employees,email'
        ]);

        try {
            $employee = Employee::where('email', $request->email)->firstOrFail();
            
            return $this->resendPassword($employee->id);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getDoctors(Request $request)
    {
        $query = Employee::with(['branch'])->whereIn('role', [
            'Dentist', 'Doctor', 'Orthodontist', 'Dental Hygienist', 
            'Prosthodontist', 'Endodontist', 'Periodontist'
        ]);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $doctors = $query->where('status', 'Active')->latest()->get();

        return response()->json(['data' => $doctors]);
    }
}

