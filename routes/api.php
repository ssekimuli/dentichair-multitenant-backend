<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\RolePermissionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/profile', [AuthController::class, 'getProfileData']);
    Route::get('/all-branch', [AuthController::class, 'allBranch']);

    // Patients
    Route::apiResource('patients', PatientController::class);
    Route::post('/patients/{patientId}/images', [PatientController::class, 'addImage']);
    Route::post('/patients/{patientId}/dental-records', [PatientController::class, 'saveDentalRecord']);
    Route::post('/patients/{patientId}/periodontal-records', [PatientController::class, 'savePeriodontalRecords']);
    Route::post('/patients/{patientId}/occlusal-records', [PatientController::class, 'saveOcclusalRecord']);
    Route::post('/patients/{patientId}/treatments', [PatientController::class, 'addTreatment']);
    Route::post('/patients/{patientId}/soft-tissue-records', [PatientController::class, 'addSoftTissueRecord']);
    Route::post('/patients/{patientId}/orthodontic-records', [PatientController::class, 'addOrthodonticRecord']);

    // Appointments
    Route::apiResource('appointments', AppointmentController::class);
    Route::patch('/appointments/{id}/status', [AppointmentController::class, 'updateStatus']);

    // Invoices
    Route::apiResource('invoices', InvoiceController::class);
    Route::post('/invoices/{id}/payments', [InvoiceController::class, 'addPayment']);

    // Employees
    Route::apiResource('employees', EmployeeController::class);

    // Management
    Route::prefix('management')->group(function () {
        // Payroll
        Route::get('/payrolls', [ManagementController::class, 'getPayrolls']);
        Route::post('/payrolls', [ManagementController::class, 'createPayroll']);

        // Attendance
        Route::get('/attendance', [ManagementController::class, 'getAttendance']);
        Route::post('/attendance', [ManagementController::class, 'markAttendance']);

        // Inventory
        Route::get('/inventory', [ManagementController::class, 'getInventory']);
        Route::patch('/inventory/{id}', [ManagementController::class, 'updateInventory']);

        // Laboratory
        Route::get('/lab-orders', [ManagementController::class, 'getLabOrders']);
        Route::patch('/lab-orders/{id}/status', [ManagementController::class, 'updateLabOrderStatus']);

        // Leave Requests
        Route::get('/leave-requests', [ManagementController::class, 'getLeaveRequests']);
        Route::patch('/leave-requests/{id}', [ManagementController::class, 'updateLeaveRequest']);

        // Staff Advances
        Route::get('/staff-advances', [ManagementController::class, 'getStaffAdvances']);
        Route::patch('/staff-advances/{id}', [ManagementController::class, 'updateStaffAdvance']);

        // Expenses
        Route::get('/expenses', [ManagementController::class, 'getExpenses']);

        // Chart Data
        Route::get('/chart-data', [ManagementController::class, 'getChartData']);
    });

    // Get all roles with permissions (Super Admin only)
    Route::get('/settings/roles', [RolePermissionController::class, 'getAllRolesWithPermissions']);
    
    // Update role permissions (Super Admin only)
    Route::put('/settings/roles/{role_id}', [RolePermissionController::class, 'updateRolePermissions']);
    
    // Get role by ID (Super Admin only)
    Route::get('/settings/roles/{role_id}', [RolePermissionController::class, 'getRoleById']);
    
    // Get simple roles list (Super Admin & HR)
    Route::get('/roles', [RolePermissionController::class, 'getRolesList']);
});