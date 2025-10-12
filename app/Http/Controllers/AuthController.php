<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * @group Authentication
 * 
 * Endpoints for user authentication and profile management in the Dental Management System.
 * This API handles user login with token-based authentication and retrieval of profile data.
 */
class AuthController extends Controller
{
    /**
     * User Login
     * 
     * Authenticate a user using email and password and return an access token.
     * For this prototype system, any valid email with password "password" will be accepted.
     * 
     * @bodyParam email string required The user's email address. Example: lisa.cuddy@example.com
     * @bodyParam password string required The user's password. Must be "password" for prototype. Example: password
     * 
     * @response 200 {
     *   "token": "1|a1b2c3d4e5f6g7h8i9j0",
     *   "user": {
     *     "role": "Super Admin",
     *     "branchId": "b1",
     *     "email": "lisa.cuddy@example.com",
     *     "name": "Dr. Lisa Cuddy",
     *     "avatar": "https://picsum.photos/seed/emp1/200/200"
     *   }
     * }
     * @response 401 {
     *   "error": "Invalid credentials"
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "email": ["The email field is required."],
     *     "password": ["The password field is required."]
     *   }
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $employee = Employee::where('email', $request->email)->first();

        // For prototype, accept any login if password is "password"
        if ($employee && $request->password === 'password') {
            // Create a new token for the user
            $token = $employee->createToken('auth-token')->plainTextToken;
            
            return response()->json([
                'token' => $token,
                'user' => [
                    'role' => $employee->role,
                    'branchId' => $employee->branch_id,
                    'email' => $employee->email,
                    'name' => $employee->name,
                    'avatar' => $employee->avatar,
                ]
            ]);
        }

        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    /**
     * User Logout
     * 
     * Revoke the current access token to log the user out.
     * Requires Bearer token authentication.
     * 
     * @authenticated
     * 
     * @response 200 {
     *   "message": "Successfully logged out"
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // Revoke the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Get Current User
     * 
     * Get the authenticated user's information using their token.
     * Requires Bearer token authentication.
     * 
     * @authenticated
     * 
     * @response 200 {
     *   "user": {
     *     "role": "Super Admin",
     *     "branchId": "b1",
     *     "email": "lisa.cuddy@example.com",
     *     "name": "Dr. Lisa Cuddy",
     *     "avatar": "https://picsum.photos/seed/emp1/200/200"
     *   }
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function user(Request $request)
    {
        $employee = $request->user();
        
        return response()->json([
            'user' => [
                'role' => $employee->role,
                'branchId' => $employee->branch_id,
                'email' => $employee->email,
                'name' => $employee->name,
                'avatar' => $employee->avatar,
            ]
        ]);
    }

    /**
     * Get Profile Data
     * 
     * Retrieve comprehensive profile information for the authenticated user including:
     * - Employee details
     * - Attendance statistics
     * - Appointment statistics  
     * - Payroll history
     * - Recent appointments
     * 
     * Requires Bearer token authentication.
     * 
     * @authenticated
     * 
     * @response 200 {
     *   "employee": {
     *     "id": "emp1",
     *     "name": "Dr. Lisa Cuddy",
     *     "avatar": "https://picsum.photos/seed/emp1/200/200",
     *     "email": "lisa.cuddy@example.com",
     *     "role": "Super Admin",
     *     "status": "Active",
     *     "salary": 150000,
     *     "hire_date": "2020-01-15",
     *     "branch_id": "b1",
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z"
     *   },
     *   "stats": {
     *     "totalPaid": 15000,
     *     "presentDays": 20,
     *     "absentDays": 2,
     *     "onLeaveDays": 1,
     *     "totalAppointments": 45,
     *     "uniquePatients": 30,
     *     "recentAppointments": [
     *       {
     *         "id": "a1",
     *         "patient_name": "John Doe",
     *         "treatment": "Check-up",
     *         "status": "Completed",
     *         "date": "2024-07-20",
     *         "time": "10:00 AM"
     *       }
     *     ]
     *   },
     *   "payrolls": [
     *     {
     *       "id": "pay1",
     *       "employee_id": "emp1",
     *       "employee_name": "Dr. Lisa Cuddy",
     *       "pay_period": "2024-06-01 to 2024-06-15",
     *       "gross_pay": 6250,
     *       "deductions": 1200,
     *       "net_pay": 5050,
     *       "pay_date": "2024-06-15",
     *       "status": "Paid",
     *       "branch_id": "b1",
     *       "created_at": "2024-01-01T00:00:00.000000Z",
     *       "updated_at": "2024-01-01T00:00:00.000000Z"
     *     }
     *   ]
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfileData(Request $request)
    {
        $employee = $request->user();
        
        $employeePayrolls = $employee->payrolls;
        $totalPaid = $employeePayrolls->sum('net_pay');

        $employeeAttendance = $employee->attendance;
        $presentDays = $employeeAttendance->where('status', 'Present')->count();
        $absentDays = $employeeAttendance->where('status', 'Absent')->count();
        $onLeaveDays = $employeeAttendance->where('status', 'On Leave')->count();

        $employeeAppointments = $employee->appointments;
        $uniquePatients = $employeeAppointments->unique('patient_id')->count();

        $stats = [
            'totalPaid' => $totalPaid,
            'presentDays' => $presentDays,
            'absentDays' => $absentDays,
            'onLeaveDays' => $onLeaveDays,
            'totalAppointments' => $employeeAppointments->count(),
            'uniquePatients' => $uniquePatients,
            'recentAppointments' => $employeeAppointments->sortByDesc('date')->take(5)->values(),
        ];

        return response()->json([
            'employee' => $employee,
            'stats' => $stats,
            'payrolls' => $employeePayrolls,
        ]);
    }
}