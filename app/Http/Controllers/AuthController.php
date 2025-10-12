<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $employee = Employee::where('email', $request->email)->first();

        // For prototype, accept any login if password is "password"
        if ($employee && $request->password === 'password') {
            return response()->json([
                'role' => $employee->role,
                'branchId' => $employee->branch_id,
                'email' => $employee->email,
                'name' => $employee->name,
                'avatar' => $employee->avatar,
            ]);
        }

        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    public function getProfileData($userEmail)
    {
        $employee = Employee::where('email', $userEmail)->first();
        
        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

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