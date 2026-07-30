<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Business;

class BusinessController extends Controller
{
    public function index()
    {
        $businesses = Business::all();
        return response()->json($businesses);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'details' => 'nullable|string|max:1000',
        ]);

        $business = Business::create([
            'name' => $request->name,
            'details' => $request->details,
        ]);

        return response()->json($business, 201);
    }

    public function changeEmail(Request $request)
    {
        $request->validate([
            'old_email' => 'required|email',
            'new_email' => 'required|email|unique:employees,email',
            'phone' => 'nullable|string',
        ]);

        $oldEmail = $request->old_email;
        $newEmail = $request->new_email;

        // Update employee table
        $employeeUpdated = \App\Models\Employee::where('email', $oldEmail)->update(['email' => $newEmail]);

        // Update business table
        $businessUpdated = Business::where('business_email', $oldEmail)->update(['business_email' => $newEmail]);

        // Send Notification
        $message = "Hello, the email address for your business account has been updated from {$oldEmail} to {$newEmail}.";
        
        // 1. Send Email to both old and new addresses
        try {
            \Illuminate\Support\Facades\Mail::raw($message, function($msg) use ($oldEmail, $newEmail) {
                $msg->to([$oldEmail, $newEmail])->subject('Business Email Updated');
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to send email update notification: " . $e->getMessage());
        }

        // 2. Send SMS if phone is provided
        if ($request->filled('phone')) {
            \App\Services\NotificationService::sendSms($request->phone, $message);
        }

        return response()->json([
            'message' => 'Email updated successfully and notifications sent',
            'employee_updated' => (bool)$employeeUpdated,
            'business_updated' => (bool)$businessUpdated
        ]);
    }

    public function toggleStatus(Request $request)
    {
        $request->validate([
            'business_email' => 'required|email',
            'status' => 'required|string|in:active,inactive'
        ]);

        $email = $request->business_email;
        $status = $request->status;

        // Update business table
        $businessUpdated = Business::where('business_email', $email)->update(['business_status' => $status]);

        // Update employee table (optional, but good practice to sync the main user account)
        $employeeUpdated = \App\Models\Employee::where('email', $email)->update(['status' => $status]);

        return response()->json([
            'message' => "Business account marked as {$status}",
            'business_updated' => (bool)$businessUpdated,
            'employee_updated' => (bool)$employeeUpdated
        ]);
    }

    public function createAdmin(Request $request)
    {
        $request->validate([
            'business_name' => 'required|string',
            'branch_name' => 'required|string',
            'name' => 'required|string',
            'email' => 'required|email|unique:employees,email',
            'phone' => 'required|string',
        ]);

        // 1. Create Business
        $business = Business::create([
            'name' => $request->business_name,
            'business_email' => $request->email,
            'joined_date' => now(),
            'business_status' => 'active'
        ]);

        // 2. Set Global Clinic Name
        $globalSetting = \App\Models\GlobalSetting::getSettings();
        $globalSetting->clinic_name = $request->business_name;
        $globalSetting->save();

        // 3. Create Branch
        $branch = \App\Models\Branch::create([
            'name' => $request->branch_name,
        ]);

        // 4. Create Employee (SuperAdmin)
        $password = \Illuminate\Support\Str::random(10);
        $employee = \App\Models\Employee::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => 'Super Admin', 
            'role_id' =>1,
            'status' => 'Active',
            'hire_date' => now(),
            'branch_id' => $branch->id,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
        ]);

        // 5. Send Welcome Email and SMS
        $message = \App\Services\NotificationService::getWelcomeMessage(
            $request->business_name,
            $request->email,
            $password
        );

        // Send Email
        try {
            \Illuminate\Support\Facades\Mail::raw($message, function($msg) use ($request) {
                $msg->to($request->email)->subject('Welcome to DentiChair!');
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to send welcome email: " . $e->getMessage());
        }

        // Send SMS
        \App\Services\NotificationService::sendSms($request->phone, $message);

        return response()->json([
            'message' => 'Admin created successfully',
            'business' => $business,
            'branch' => $branch,
            'employee' => $employee,
        ], 201);
    }
}
