<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\PharmacyController;
use App\Http\Controllers\BusinessController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-otp', [AuthController::class, 'verifyOTP']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Protected routes
Route::middleware(['auth:sanctum', 'default_branch'])->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/profile', [AuthController::class, 'getProfileData']);
    Route::get('/all-branch', [AuthController::class, 'allBranch']);

    // Imports
    Route::post('/import/parse-headers', [ImportController::class, 'parseHeaders']);
    Route::post('/import/start', [ImportController::class, 'startImport']);
    Route::get('/import/progress/{id}', [ImportController::class, 'getProgress']);
    Route::get('/import/templates', [ImportController::class, 'listTemplates']);
    Route::get('/import/templates/download/{filename}', [ImportController::class, 'downloadTemplate']);
    Route::post('/branches', [ManagementController::class, 'createBranch']);
    Route::put('/branches/{id}', [ManagementController::class, 'updateBranch']);
    Route::delete('/branches/{id}', [ManagementController::class, 'deleteBranch']);
    Route::get('/currencies', [ManagementController::class, 'getCurrencies']);

    // Businesses
    Route::get('/businesses', [BusinessController::class, 'index']);
    Route::post('/businesses', [BusinessController::class, 'store']);

    // Patients
    Route::apiResource('patients', PatientController::class);
    Route::put('/patients/{id}/next-visit', [PatientController::class, 'updateNextVisit']);
    Route::get('/patients-list', [PatientController::class, 'patientList']);
    Route::get('/patients/{id}/invoices', [PatientController::class, 'getInvoices']);


    // Patient Images
    Route::get('/patients/{patientId}/images', [PatientController::class, 'getImages']);
    Route::post('/patients/{patientId}/images', [PatientController::class, 'addImage']);
    Route::put('/patients/{patientId}/images/{imageId}', [PatientController::class, 'updateImage']);
    Route::delete('/patients/{patientId}/images/{imageId}', [PatientController::class, 'deleteImage']);

    // Patient history
    Route::get('/patients/{id}/history', [PatientController::class, 'getHistory']);

    // Odontogram/Dental Records
    Route::get('/patients/{patientId}/odontogram-records', [PatientController::class, 'getDentalRecords']);
    Route::post('/patients/{patientId}/odontogram-records', [PatientController::class, 'saveDentalRecord']);
    Route::put('/patients/{patientId}/odontogram-records/{recordId}', [PatientController::class, 'updateDentalRecord']);
    Route::delete('/patients/{patientId}/odontogram-records/{recordId}', [PatientController::class, 'deleteDentalRecord']);
    Route::get('/patients/{patientId}/tooth/{toothId}/history', [PatientController::class, 'getToothHistory']);

    // Periodontal Records
    Route::get('/patients/{patientId}/periodontal-records', [PatientController::class, 'getPeriodontalRecords']);
    Route::post('/patients/{patientId}/periodontal-records', [PatientController::class, 'savePeriodontalRecords']);
    Route::put('/patients/{patientId}/periodontal-records/{recordId}', [PatientController::class, 'updatePeriodontalRecord']);
    Route::delete('/patients/{patientId}/periodontal-records/{recordId}', [PatientController::class, 'deletePeriodontalRecord']);

    // Occlusal Records
    Route::get('/patients/{patientId}/occlusal-records', [PatientController::class, 'getOcclusalRecords']);
    Route::post('/patients/{patientId}/occlusal-records', [PatientController::class, 'saveOcclusalRecord']);
    Route::put('/patients/{patientId}/occlusal-records', [PatientController::class, 'updateOcclusalRecord']);
    Route::delete('/patients/{patientId}/occlusal-records', [PatientController::class, 'deleteOcclusalRecord']);

    // Treatment Plans
    Route::get('/patients/{patientId}/treatments', [PatientController::class, 'getTreatments']);
    Route::post('/patients/{patientId}/treatments', [PatientController::class, 'addTreatment']);
    Route::put('/patients/{patientId}/treatments/{treatmentId}', [PatientController::class, 'updateTreatment']);
    Route::patch('/patients/{patientId}/treatments/{treatmentId}/status', [PatientController::class, 'updateTreatmentStatus']);
    Route::delete('/patients/{patientId}/treatments/{treatmentId}', [PatientController::class, 'deleteTreatment']);

    // Soft Tissue Records
    Route::get('/patients/{patientId}/soft-tissue-records', [PatientController::class, 'getSoftTissueRecords']);
    Route::post('/patients/{patientId}/soft-tissue-records', [PatientController::class, 'addSoftTissueRecord']);
    Route::put('/patients/{patientId}/soft-tissue-records/{recordId}', [PatientController::class, 'updateSoftTissueRecord']);
    Route::delete('/patients/{patientId}/soft-tissue-records/{recordId}', [PatientController::class, 'deleteSoftTissueRecord']);

    // Orthodontic Records
    Route::get('/patients/{patientId}/orthodontic-records', [PatientController::class, 'getOrthodonticRecords']);
    Route::post('/patients/{patientId}/orthodontic-records', [PatientController::class, 'addOrthodonticRecord']);
    Route::put('/patients/{patientId}/orthodontic-records/{recordId}', [PatientController::class, 'updateOrthodonticRecord']);
    Route::delete('/patients/{patientId}/orthodontic-records/{recordId}', [PatientController::class, 'deleteOrthodonticRecord']);

    // Endodontic Records
    Route::get('/patients/{patientId}/endodontic-records', [PatientController::class, 'getEndodonticRecords']);
    Route::post('/patients/{patientId}/endodontic-records', [PatientController::class, 'addEndodonticRecord']);
    Route::put('/patients/{patientId}/endodontic-records/{recordId}', [PatientController::class, 'updateEndodonticRecord']);
    Route::delete('/patients/{patientId}/endodontic-records/{recordId}', [PatientController::class, 'deleteEndodonticRecord']);

    // Appointments
    Route::apiResource('appointments', AppointmentController::class);
    Route::patch('/appointments/{id}/status', [AppointmentController::class, 'updateStatus']);

    // Invoices
    Route::apiResource('invoices', InvoiceController::class);
    Route::get('/income-records', [InvoiceController::class, 'getIncomeRecords']);
    Route::post('/invoices/{id}/payments', [InvoiceController::class, 'addPayment']);

    // Procedures
    Route::apiResource('procedures', ProcedureController::class);

    // Employees
    Route::post('/employees/{id}/resend-password', [EmployeeController::class, 'resendPassword']);
    Route::apiResource('employees', EmployeeController::class);
    Route::get('/doctors', [EmployeeController::class, 'getDoctors']);
    Route::post('/attendance', [ManagementController::class, 'markAttendance']);

    // Dashboard summary
    Route::get('/dashboard-summary', [ManagementController::class, 'getPracticeSummary']);
    Route::get('/practice-trends', [ManagementController::class, 'getPracticeTrends']);
    Route::get('/financial-summary', [ManagementController::class, 'getFinancialSummary']);
    Route::get('/expense-records', [ManagementController::class, 'getExpenseRecords']);
    Route::get('/reports/overview', [ManagementController::class, 'getReportsOverview']);

    // Management
    Route::prefix('management')->group(function () {
        // Payroll
        Route::get('/payrolls', [ManagementController::class, 'getPayrolls']);
        Route::post('/payrolls', [ManagementController::class, 'createPayroll']);
        Route::patch('/payrolls/{id}/approve', [ManagementController::class, 'approvePayroll']);

        // Attendance
        Route::get('/attendance', [ManagementController::class, 'getAttendance']);
        Route::post('/attendance', [ManagementController::class, 'markAttendance']);

        // Inventory
        Route::get('/inventory', [ManagementController::class, 'getInventory']);
        Route::post('/inventory', [ManagementController::class, 'createInventoryItem']);
        Route::patch('/inventory/{id}', [ManagementController::class, 'updateInventory']);

        // Laboratory
        Route::get('/lab-orders', [ManagementController::class, 'getLabOrders']);
        Route::post('/lab-orders', [ManagementController::class, 'createLabOrder']);
        Route::patch('/lab-orders/{id}/status', [ManagementController::class, 'updateLabOrderStatus']);

        // Leave Requests
        Route::post('/leave-requests', [ManagementController::class, 'createLeaveRequest']);
        Route::get('/get-leave-requests', [ManagementController::class, 'getLeaveRequests']);
        Route::patch('/leave-requests/{id}', [ManagementController::class, 'updateLeaveRequest']);

        // Staff Advances
        Route::get('/staff-advances', [ManagementController::class, 'getStaffAdvances']);
        Route::post('/staff-advances', [ManagementController::class, 'AddStaffAdvance']);
        Route::patch('/staff-advances/{id}', [ManagementController::class, 'updateStaffAdvance']);

        // Expenses
        Route::get('/expenses', [ManagementController::class, 'getExpenses']);
        Route::post('/expenses', [ManagementController::class, 'createExpense']);

        // Chart Data
        Route::get('/chart-data', [ManagementController::class, 'getChartData']);
        Route::get('/dashboard-summary', [ManagementController::class, 'getPracticeSummary']);
    });

    // Get all roles with permissions (Super Admin only)
    Route::get('/settings/roles', [RolePermissionController::class, 'getAllRolesWithPermissions']);
    Route::post('/settings/roles', [RolePermissionController::class, 'createRole']);
    Route::patch('/settings/roles/{role_id}', [RolePermissionController::class, 'updateRoleName']);
    Route::delete('/settings/roles/{role_id}', [RolePermissionController::class, 'deleteRole']);
    
    // Update role permissions (Super Admin only)
    Route::put('/settings/roles/{role_id}', [RolePermissionController::class, 'updateRolePermissions']);
    
    // Get role by ID (Super Admin only)
    Route::get('/settings/roles/{role_id}', [RolePermissionController::class, 'getRoleById']);
    
    // Get simple roles list (Super Admin & HR)
    Route::get('/roles', [RolePermissionController::class, 'getRolesList']);

    // Imports & Templates
    Route::post('/import/parse', [ImportController::class, 'parseHeaders']);
    Route::post('/import/start', [ImportController::class, 'startImport']);
    Route::get('/import/progress/{id}', [ImportController::class, 'getProgress']);
    Route::get('/import/templates', [ImportController::class, 'listTemplates']);
    Route::get('/import/templates/download/{filename}', [ImportController::class, 'downloadTemplate']);

    // Pharmacy Module
    Route::get('/pharmacy/medicines', [PharmacyController::class, 'getMedicines']);
    Route::post('/pharmacy/medicines', [PharmacyController::class, 'storeMedicine']);
    Route::put('/pharmacy/medicines/{id}', [PharmacyController::class, 'updateMedicine']);
    Route::delete('/pharmacy/medicines/{id}', [PharmacyController::class, 'deleteMedicine']);
    Route::get('/pharmacy/prescriptions', [PharmacyController::class, 'getPrescriptions']);
    Route::post('/pharmacy/prescriptions', [PharmacyController::class, 'storePrescription']);
    Route::patch('/pharmacy/prescriptions/{id}/status', [PharmacyController::class, 'updatePrescriptionStatus']);
    Route::put('/pharmacy/prescriptions/{id}', [PharmacyController::class, 'updatePrescription']);
    Route::delete('/pharmacy/prescriptions/{id}', [PharmacyController::class, 'deletePrescription']);
    Route::get('/patients/{patientId}/prescriptions', [PharmacyController::class, 'getPatientPrescriptions']);
});