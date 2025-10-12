<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
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
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'sometimes|required_without:phone|email',
            'phone' => 'sometimes|required_without:email|string',
        ]);

        // Check if at least one field is provided
        if (empty($request->email) && empty($request->phone)) {
            return response()->json(['error' => 'Either email or phone number is required'], 400);
        }

        // Find user by email or phone
        $employee = null;
        $identifier = null;
        $method = null;

        if (!empty($request->email)) {
            $employee = Employee::where('email', $request->email)->first();
            $identifier = $request->email;
            $method = 'email';
        } elseif (!empty($request->phone)) {
            $employee = Employee::where('phone', $request->phone)->first();
            $identifier = $request->phone;
            $method = 'sms';
        }

        if (!$employee) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store OTP in cache with 10 minutes expiration
        $cacheKey = 'password_reset_' . $identifier;
        Cache::put($cacheKey, [
            'otp' => $otp,
            'user_id' => $employee->id,
            'method' => $method
        ], now()->addMinutes(10));

        // Send OTP based on method
        $response = [];
        
        if ($method === 'email') {
            $this->sendOtpEmail($employee->email, $otp, $employee->name);
            
            // Mask email for response
            $emailParts = explode('@', $employee->email);
            $maskedEmail = substr($emailParts[0], 0, 1) . '***@' . $emailParts[1];
            
            $response = [
                'message' => 'OTP sent successfully to your email',
                'method' => 'email',
                'masked_email' => $maskedEmail,
            ];
        } elseif ($method === 'sms') {
            $this->sendOtpSms($employee->phone, $otp);
            
            // Mask phone for response
            $phone = $employee->phone;
            $maskedPhone = substr($phone, 0, 2) . '******' . substr($phone, -2);
            
            $response = [
                'message' => 'OTP sent successfully to your phone',
                'method' => 'sms',
                'masked_phone' => $maskedPhone,
            ];
        }

        // For development/testing, include OTP in response
        if (app()->environment('local')) {
            $response['otp'] = $otp;
        }

        return response()->json($response);
    }

    /**
     * Verify OTP and Reset Password
     * 
     * Verify the OTP and reset user's password.
     * User can provide either email or phone number used to request OTP.
     * 
     * @bodyParam email string optional The user's email address. Example: lisa.cuddy@example.com
     * @bodyParam phone string optional The user's phone number. Example: +1234567890
     * @bodyParam otp string required The OTP received by user. Example: 123456
     * @bodyParam password string required The new password. Example: newpassword123
     * @bodyParam password_confirmation string required The password confirmation. Example: newpassword123
     * 
     * @response 200 {
     *   "message": "Password reset successfully"
     * }
     * @response 400 {
     *   "error": "Invalid or expired OTP"
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
     *     "phone": ["The phone must be a valid phone number."],
     *     "otp": ["The otp field is required."],
     *     "password": ["The password field is required."]
     *   }
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'sometimes|required_without:phone|email',
            'phone' => 'sometimes|required_without:email|string',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Check if at least one field is provided
        if (empty($request->email) && empty($request->phone)) {
            return response()->json(['error' => 'Either email or phone number is required'], 400);
        }

        // Find user by email or phone
        $employee = null;
        $identifier = null;

        if (!empty($request->email)) {
            $employee = Employee::where('email', $request->email)->first();
            $identifier = $request->email;
        } elseif (!empty($request->phone)) {
            $employee = Employee::where('phone', $request->phone)->first();
            $identifier = $request->phone;
        }

        if (!$employee) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Verify OTP
        $cacheKey = 'password_reset_' . $identifier;
        $storedData = Cache::get($cacheKey);

        if (!$storedData || $storedData['otp'] !== $request->otp || $storedData['user_id'] !== $employee->id) {
            return response()->json(['error' => 'Invalid or expired OTP'], 400);
        }

        // Update password
        // For prototype, we'll store as plain text, but in production use Hash::make()
        $employee->password =  Hash::make($request->password);
        $employee->save();

        // Clear OTP from cache
        Cache::forget($cacheKey);

        return response()->json(['message' => 'Password reset successfully']);
    }

    /**
     * Resend OTP
     * 
     * Resend OTP for password reset using either email or phone.
     * 
     * @bodyParam email string optional The user's email address. Example: lisa.cuddy@example.com
     * @bodyParam phone string optional The user's phone number. Example: +1234567890
     * 
     * @response 200 {
     *   "message": "OTP resent successfully",
     *   "method": "email",
     *   "masked_email": "l***@example.com"
     * }
     * @response 200 {
     *   "message": "OTP resent successfully",
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
    public function resendOtp(Request $request)
    {
        return $this->forgotPassword($request);
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
        // For prototype, we'll log the OTP instead of actually sending email
        // In production, integrate with your email service (Mailgun, SendGrid, etc.)
        
        // \Log::info("OTP sent to {$email}: {$otp} for user {$name}");
        
        // Example of actual email sending (uncomment in production):
        /*
        Mail::send('emails.password-reset', ['otp' => $otp, 'name' => $name], function ($message) use ($email) {
            $message->to($email)
                    ->subject('Password Reset OTP - Dental Management System');
        });
        */
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
}