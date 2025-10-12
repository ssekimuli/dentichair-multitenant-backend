<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ManagementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/profile/{userEmail}', [AuthController::class, 'getProfileData']);

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
});