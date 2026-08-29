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
use App\Http\Controllers\MigrationController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\LabServiceController;
use App\Http\Controllers\LabServicePriceController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-otp', [AuthController::class, 'verifyOTP']);
Route::post('/user-reset-password', [AuthController::class, 'resetPassword']);
Route::post('/send-whatsapp', [NotificationController::class, 'sendWhatsApp']);


Route::middleware([\App\Http\Middleware\VerifyAppKey::class])->group(function () {
    // Notifications API
    //change business emaill
    //activate/deactive business account
    //Create Admin
    //Reset Password
    Route::post('/send-sms', [NotificationController::class, 'sendSms']);
    Route::post('/send-whatsapp-media', [NotificationController::class, 'sendWhatsAppMedia']);
    Route::post('/change-business-email', [BusinessController::class, 'changeEmail']);
    Route::post('/toggle-business-status', [BusinessController::class, 'toggleStatus']);
    Route::post('/create-admin', [BusinessController::class, 'createAdmin']);
    Route::post('/reset-password', [EmployeeController::class, 'resetPasswordByEmail']);
    
});

// Public template downloads (browser direct access)
Route::get('/import/templates/download/{filename}', [ImportController::class, 'downloadTemplate']);
Route::get('/migrate/template/download', [MigrationController::class, 'downloadTemplate']);
Route::get('/downloads/printer-driver', [DownloadController::class, 'printerDriver']);

// Protected routes
Route::middleware(['auth:sanctum', 'default_branch'])->group(function () {

    Route::post('/top-up-sms', [NotificationController::class, 'topUpSms']);
    Route::post('/verify-transaction', [NotificationController::class, 'verifyWebhook']);
    Route::post('/update-top-up-balance', [NotificationController::class, 'updateTopUpBalance']);

    Route::get('/activity-logs', [\App\Http\Controllers\ActivityLogController::class, 'index']);
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/profile', [AuthController::class, 'getProfileData']);
    Route::get('/business', [AuthController::class, 'getBusinessInfo']);
    Route::get('/all-branch', [AuthController::class, 'allBranch']);

    // Imports
    Route::post('/migrate', [MigrationController::class, 'migrate']);
    Route::post('/migrate/combined', [MigrationController::class, 'migrateCombined']);
    Route::post('/import/parse-headers', [ImportController::class, 'parseHeaders']);
    Route::post('/import/start', [ImportController::class, 'startImport']);
    Route::get('/import/progress/{id}', [ImportController::class, 'getProgress']);
    Route::get('/import/templates', [ImportController::class, 'listTemplates']);
    Route::post('/branches', [ManagementController::class, 'createBranch']);
    Route::put('/branches/{id}', [ManagementController::class, 'updateBranch']);
    Route::delete('/branches/{id}', [ManagementController::class, 'deleteBranch']);
    Route::get('/currencies', [ManagementController::class, 'getCurrencies']);

    // Businesses
    Route::get('/businesses', [BusinessController::class, 'index']);
    Route::post('/businesses', [BusinessController::class, 'store']);

    // Clients
    Route::apiResource('clients', App\Http\Controllers\ClientController::class);

    // Patients
    Route::apiResource('patients', PatientController::class);
    Route::put('/patients/{id}/next-visit', [PatientController::class, 'updateNextVisit']);
    Route::get('/patients-list', [PatientController::class, 'patientList']);
    Route::get('/patients/{id}/invoices', [PatientController::class, 'getInvoices']);
    Route::post('/patients/{id}/avatar', [PatientController::class, 'updateAvatar']);

    // Appointments
    Route::apiResource('appointments', AppointmentController::class);


    Route::get('/patients/{patientId}/images', [PatientController::class, 'getImages']);
    Route::post('/patients/{patientId}/images/send', [PatientController::class, 'sendDocuments']);
    Route::post('/patients/{patientId}/periodontal/send', [PatientController::class, 'sendPeriodontalResult']);
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
    Route::post('/patients/{patient}/endodontic-records', [App\Http\Controllers\EndodonticRecordController::class, 'store']);
    Route::put('/patients/{patient}/endodontic-records/{endodonticRecord}', [App\Http\Controllers\EndodonticRecordController::class, 'update']);
    Route::delete('/patients/{patient}/endodontic-records/{endodonticRecord}', [App\Http\Controllers\EndodonticRecordController::class, 'destroy']);

    Route::post('/patients/{patient}/restorative-records', [App\Http\Controllers\RestorativeRecordController::class, 'store']);
    Route::put('/patients/{patient}/restorative-records/{restorativeRecord}', [App\Http\Controllers\RestorativeRecordController::class, 'update']);
    Route::delete('/patients/{patient}/restorative-records/{restorativeRecord}', [App\Http\Controllers\RestorativeRecordController::class, 'destroy']);

    // Route::post('/patients/{patient}/treatments', [App\Http\Controllers\TreatmentController::class, 'store']);
    Route::patch('/appointments/{id}/status', [AppointmentController::class, 'updateStatus']);

    // Invoices
    Route::apiResource('invoices', InvoiceController::class);

    // Insurance & Claims
    Route::apiResource('patient-insurances', \App\Http\Controllers\PatientInsuranceController::class);
    Route::get('/patients/{patientId}/insurances', [\App\Http\Controllers\PatientInsuranceController::class, 'getByPatient']);
    Route::get('/insurance-claims/stats', [\App\Http\Controllers\InsuranceClaimController::class, 'stats']);
    Route::post('/insurance-claims/{id}/documents', [\App\Http\Controllers\InsuranceClaimController::class, 'uploadDocument']);
    Route::delete('/insurance-claims/{id}/documents/{documentId}', [\App\Http\Controllers\InsuranceClaimController::class, 'deleteDocument']);
    Route::apiResource('insurance-claims', \App\Http\Controllers\InsuranceClaimController::class);
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

    // Lab Services Management
    Route::apiResource('lab-services', LabServiceController::class);
    Route::get('lab-services/{labService}/prices', [LabServiceController::class, 'prices']);
    Route::apiResource('lab-service-prices', LabServicePriceController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('lab-services/price-for-date', [LabServicePriceController::class, 'priceForDate']);

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
        Route::put('/lab-orders/{id}', [ManagementController::class, 'updateLabOrder']);
        Route::delete('/lab-orders/{id}', [ManagementController::class, 'deleteLabOrder']);
        Route::patch('/lab-orders/{id}/status', [ManagementController::class, 'updateLabOrderStatus']);
        Route::post('/lab-orders/{id}/send', [ManagementController::class, 'sendLabOrder']);

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
        Route::patch('/expenses/{id}', [ManagementController::class, 'updateExpense']);
        Route::delete('/expenses/{id}', [ManagementController::class, 'deleteExpense']);

        // Chart Data
        Route::get('/chart-data', [ManagementController::class, 'getChartData']);
        Route::get('/dashboard-summary', [ManagementController::class, 'getPracticeSummary']);
    });

    // Branch Settings
    Route::get('/settings/branch', [\App\Http\Controllers\BranchSettingController::class, 'getSettings']);
    Route::post('/settings/branch', [\App\Http\Controllers\BranchSettingController::class, 'updateSettings']);
    Route::post('/settings/branch/top-up', [\App\Http\Controllers\BranchSettingController::class, 'topUpCredits']);

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

    // --- NEW INSURANCE MODULE ROUTES ---
    Route::prefix('insurance')->group(function () {
        Route::get('companies', [\App\Http\Controllers\Api\Insurance\InsuranceCompanyController::class, 'index']);
        Route::post('companies', [\App\Http\Controllers\Api\Insurance\InsuranceCompanyController::class, 'store']);
        Route::put('companies/{id}', [\App\Http\Controllers\Api\Insurance\InsuranceCompanyController::class, 'update']);
        Route::delete('companies/{id}', [\App\Http\Controllers\Api\Insurance\InsuranceCompanyController::class, 'destroy']);
        Route::patch('companies/{id}/status', [\App\Http\Controllers\Api\Insurance\InsuranceCompanyController::class, 'updateStatus']);
        
        Route::get('packages', [\App\Http\Controllers\Api\Insurance\InsurancePackageController::class, 'index']);
        Route::post('packages', [\App\Http\Controllers\Api\Insurance\InsurancePackageController::class, 'store']);
        Route::put('packages/{id}', [\App\Http\Controllers\Api\Insurance\InsurancePackageController::class, 'update']);
        Route::delete('packages/{id}', [\App\Http\Controllers\Api\Insurance\InsurancePackageController::class, 'destroy']);
        Route::patch('packages/{id}/status', [\App\Http\Controllers\Api\Insurance\InsurancePackageController::class, 'updateStatus']);
        
        Route::get('attached-policies', \App\Http\Controllers\Api\Insurance\AllAttachedPoliciesController::class);
        
        Route::get('packages/{packageId}/treatments', [\App\Http\Controllers\Api\Insurance\InsurancePackageTreatmentController::class, 'index']);
        Route::post('packages/{packageId}/treatments', [\App\Http\Controllers\Api\Insurance\InsurancePackageTreatmentController::class, 'store']);
        Route::delete('packages/{packageId}/treatments/{id}', [\App\Http\Controllers\Api\Insurance\InsurancePackageTreatmentController::class, 'destroy']);
        
        Route::get('policies', [\App\Http\Controllers\Api\Insurance\InsurancePolicyController::class, 'index']);
        Route::post('policies', [\App\Http\Controllers\Api\Insurance\InsurancePolicyController::class, 'store']);
        Route::put('policies/{id}', [\App\Http\Controllers\Api\Insurance\InsurancePolicyController::class, 'update']);
        Route::delete('policies/{id}', [\App\Http\Controllers\Api\Insurance\InsurancePolicyController::class, 'destroy']);
        Route::patch('policies/{id}/status', [\App\Http\Controllers\Api\Insurance\InsurancePolicyController::class, 'updateStatus']);
        
        Route::get('patients/{patient}/policies', [\App\Http\Controllers\Api\Insurance\PatientInsuranceController::class, 'index']);
        Route::post('patients/{patient}/policies', [\App\Http\Controllers\Api\Insurance\PatientInsuranceController::class, 'attach']);
        Route::delete('patients/{patient}/policies/{pivotId}', [\App\Http\Controllers\Api\Insurance\PatientInsuranceController::class, 'detach']);
        Route::patch('patients/{patient}/policies/{pivotId}/primary', [\App\Http\Controllers\Api\Insurance\PatientInsuranceController::class, 'setPrimary']);

        Route::post('billing/estimate/{patientPolicyId}', [\App\Http\Controllers\Api\Insurance\InsuranceBillingController::class, 'estimate']);
        Route::post('billing/invoice/{patientPolicyId}', [\App\Http\Controllers\Api\Insurance\InsuranceBillingController::class, 'createInvoice']);
    });
});



