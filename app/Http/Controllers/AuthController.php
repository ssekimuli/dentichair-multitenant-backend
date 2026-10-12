<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\GlobalSetting;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetMail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

use Illuminate\Support\Facades\Cache;

/**
 * @group Authentication
 * 
 * Endpoints for user authentication and profile management in the Dental Management System.
 * This API handles user login with token-based authentication and retrieval of profile data.
 */
class AuthController extends Controller
{

    /**
     * Get permissions based on user role_id from database
     */
    private function getPermissionsByRole($roleId)
    {
        // Get role permission record from database using role_id
        $rolePermission = RolePermission::find($roleId);

        if (!$rolePermission) {
            return [];
        }

        // Return permissions from database
        return $rolePermission->permissions ?? [];
    }

    /**
     * User Login
     * 
     * Authenticate a user using email and password and return an access token with role, permissions, and branch info.
     * 
     * @bodyParam email string required The user's email address. Example: lisa.cuddy@example.com
     * @bodyParam password string required The user's password. Example: password
     * 
     * @response 200 {
     *   "token": "1|a1b2c3d4e5f6g7h8i9j0",
     *   "user": {
     *     "role": "Super Admin",
     *     "permissions": [
     *       "users.create",
     *       "users.edit", 
     *       "users.delete",
     *       "appointments.manage",
     *       "reports.view"
     *     ],
     *     "branchId": "b1",
     *     "branchName": "Main Branch",
     *     "email": "lisa.cuddy@example.com",
     *     "name": "Dr. Lisa Cuddy",
     *     "avatar": "https://picsum.photos/seed/emp1/200/200"
     *   }
     * }
     */
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            $employee = Employee::with('branch')->where('email', $request->email)->first();

            if (!$employee || !Hash::check($request->password, $employee->password)) {
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            if ($employee->status === 'Blocked') {
                throw ValidationException::withMessages([
                    'email' => ['Your account has been blocked.'],
                ]);
            }

            // Update last login time
            $employee->update([
                'last_login_at' => now(),
            ]);

            $token = $employee->createToken('api-token')->plainTextToken;

            // Get permissions based on role
            $permissions = $this->getPermissionsByRole($employee->role_id);

            return response()->json([
                'token' => $token,
                'user' => [
                    'role' => $employee->role,
                    'permissions' => $permissions,
                    'branchId' => $employee->branch_id,
                    'email' => $employee->email,
                    'name' => $employee->name,
                    'avatar' => $employee->avatar,
                    'status' => $employee->status,
                    'hire_date' => $employee->hire_date,
                    'branch_id' => $employee->branch_id,
                    'branchName' => $employee->branch->name ?? null, // Include branch name
                ]
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error("Login error: " . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request Password Reset OTP
     * 
     * Send OTP to user's email or phone for password reset.
     * User can provide either email or phone number.
     * 
     * @bodyParam email string optional The user's email address. Example: lisa.cuddy@example.com
     * @bodyParam phone string optional The user's phone number. Example: +1234567890
     * 
     * @response 200 {
     *   "message": "OTP sent successfully",
     *   "method": "email",
     *   "masked_email": "l***@example.com"
     * }
     * @response 200 {
     *   "message": "OTP sent successfully",
     *   "method": "sms",
     *   "masked_phone": "+1*******90"
     * }
     * @response 400 {
     *   "error": "Either email or phone number is required"
     * }
     * @response 404 {
     *   "error": "User not found"
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "email": ["The email must be a valid email address."],
     *     "phone": ["The phone must be a valid phone number."]
     *   }
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */


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

    // Update other methods to use role_id instead of role for permission lookup
    public function user(Request $request)
    {
        $employee = $request->user()->load('branch');
        $permissions = $this->getPermissionsByRole($employee->role_id);

        return response()->json([
            'user' => [
                'role' => $employee->role,
                'role_id' => $employee->role_id,
                'permissions' => $permissions,
                'branchId' => $employee->branch_id,
                'branch_id' => $employee->branch_id,
                'branchName' => $employee->branch->name ?? null,
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
     * - Employee details with branch name and permissions
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
     *     "role_id": 1,
     *     "status": "Active",
     *     "salary": 150000,
     *     "hire_date": "2020-01-15",
     *     "branch_id": 1,
     *     "branch_name": "Main Branch",
     *     "permissions": [
     *       "users.create",
     *       "users.edit",
     *       "users.delete",
     *       "appointments.manage"
     *     ],
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
     *       "branch_id": 1,
     *       "created_at": "2024-01-01T00:00:00.000000Z",
     *       "updated_at": "2024-01-01T00:00:00.000000Z"
     *     }
     *   ]
     * }
     */
    public function getProfileData(Request $request)
    {
        $employee = $request->user()->load('branch');

        // Use role_id instead of role for permission lookup
        $permissions = $this->getPermissionsByRole($employee->role_id);

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

        // Build employee data with branch name and permissions
        $employeeData = [
            'id' => $employee->id,
            'name' => $employee->name,
            'avatar' => $employee->avatar,
            'email' => $employee->email,
            'role' => $employee->role,
            'role_id' => $employee->role_id, // Include numeric role_id
            'status' => $employee->status,
            'salary' => $employee->salary,
            'hire_date' => $employee->hire_date,
            'branch_id' => $employee->branch_id,
            'branch_name' => $employee->branch->name ?? null, // Include branch name
            'permissions' => $permissions, // Include permissions
            'created_at' => $employee->created_at,
            'updated_at' => $employee->updated_at,
        ];

        return response()->json([
            'employee' => $employeeData,
            'stats' => $stats,
            'payrolls' => $employeePayrolls,
        ]);
    }

    /**
     * Get Business Info
     *
     * Retrieve business/clinic information and the login user's branch details.
     * Returns clinic name, address, and the branch the user belongs to.
     *
     * Requires Bearer token authentication.
     *
     * @authenticated
     *
     * @response 200 {
     *   "clinic_name": "Downtown Clinic",
     *   "clinic_address": "123 Main St, Cityville",
     *   "branch": {
     *     "id": 1,
     *     "name": "Downtown Clinic",
     *     "address": "123 Main St, Cityville",
     *     "currency": "UGX"
     *   }
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBusinessInfo(Request $request)
    {
        $employee = $request->user();
        $business = Business::first();

        $branch = null;
        if ($employee && $employee->branch_id) {
            $branch = $employee->branch;
        }

        return response()->json([
            'clinic_name' => $business?->name,
            'clinic_address' => $business?->details ?? null,
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address ?? null,
                'currency' => $branch->currency ?? null,
            ] : null,
        ]);
    }

    /**
     * Send OTP via Email
     * 
     * @param string $email
     * @param string $otp
     * @param string $name
     * @return void
     */
    private function sendOtpEmail($email, $otp, $name)
    {
        Mail::to($email)->send(new PasswordResetMail([
            'otp' => $otp,
            'name' => $name
        ]));
    }

    /**
     * Send OTP via SMS
     * 
     * @param string $phone
     * @param string $otp
     * @return void
     */
    private function sendOtpSms($phone, $otp)
    {
        // For prototype, we'll log the OTP instead of actually sending SMS
        // In production, integrate with your SMS gateway (Twilio, etc.)

        // \Log::info("OTP sent to {$phone}: {$otp}");

        // Example of actual SMS sending (uncomment in production):
        /*
        // Integration with Twilio or other SMS service
        $client = new \Twilio\Rest\Client(env('TWILIO_SID'), env('TWILIO_TOKEN'));
        $client->messages->create(
            $phone,
            [
                'from' => env('TWILIO_PHONE'),
                'body' => "Your Dental Management System OTP is: {$otp}. Valid for 10 minutes."
            ]
        );
        */
    }

    /**
     * Get all branches
     *
     * Retrieve a list of all dental branches available in the system.
     *
     * @group Branch Management
     *
     * @response 200 scenario="Success" [
     *   {
     *     "id": 1,
     *     "name": "Downtown Clinic",
     *     "address": "123 Main St, Cityville",
     *     "created_at": "2025-10-13T12:00:00.000000Z",
     *     "updated_at": "2025-10-13T12:00:00.000000Z"
     *   },
     *   {
     *     "id": 2,
     *     "name": "Uptown Dental",
     *     "address": "456 Oak Ave, Townburg",
     *     "created_at": "2025-10-13T12:00:00.000000Z",
     *     "updated_at": "2025-10-13T12:00:00.000000Z"
     *   }
     * ]
     *
     * @response 500 scenario="Server Error" {
     *   "message": "Something went wrong while fetching branches."
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function allBranch()
    {
        try {

            $branches = Branch::latest()->get();
            return response()->json($branches);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong while fetching branches.'
            ], 500);
        }
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
        ]);

        $identifier = $request->identifier;
        $employee = Employee::where('email', $identifier)->first();

        if (!$employee) {
            return response()->json([
                'message' => 'No account found with that email or phone number.'
            ], 404);
        }

        // Generate 6-digit OTP
        $otp = strval(rand(100000, 999999));
        Cache::put('otp_' . $identifier, $otp, now()->addMinutes(15));

        // Send OTP email
        try {
            $this->sendOtpEmail($employee->email, $otp, $employee->name);
        } catch (\Exception $e) {
            \Log::error("Failed to send OTP email: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send OTP email due to mail server authentication/configuration issue.',
                'error' => $e->getMessage(),
               
            ], 500);
        }

        return response()->json([
            'message' => 'OTP sent successfully.',
            'debug_otp' => '' // return for testing convenience
        ]);
    }

    public function verifyOTP(Request $request)
    {
        try {
            $request->validate([
                'identifier' => 'required|string',
                'otp' => 'required|string',
            ]);

            $identifier = $request->identifier;
            $otp = $request->otp;

            $cachedOtp = Cache::get('otp_' . $identifier);

            if (!$cachedOtp || $cachedOtp !== $otp) {
                return response()->json([
                    'message' => 'Invalid or expired OTP.'
                ], 422);
            }

            // Mark as verified for password reset
            Cache::put('verified_otp_' . $identifier, true, now()->addMinutes(15));

            return response()->json([
                'message' => 'OTP verified successfully.'
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error("OTP verification error: " . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred during OTP verification. Please try again.'
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'identifier' => 'required|string',
                'password' => 'required|string|min:6',
            ]);

            $identifier = $request->identifier;
            $password = $request->password;

            if (!Cache::has('verified_otp_' . $identifier)) {
                return response()->json([
                    'message' => 'Please verify OTP first.'
                ], 403);
            }

            $employee = Employee::where('email', $identifier)->first();

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found.'
                ], 404);
            }

            $employee->password = Hash::make($password);
            $employee->save();

            Cache::forget('verified_otp_' . $identifier);
            Cache::forget('otp_' . $identifier);

            return response()->json([
                'message' => 'Password reset successfully.'
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error("Password reset error: " . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred during password reset. Please try again.'
            ], 500);
        }
    }
}
