#!/bin/bash

echo "Creating all models and controllers for Dental Management System..."

# Create Models
echo "Creating models..."
php artisan make:model Branch
php artisan make:model Treatment
php artisan make:model Patient
php artisan make:model DentalRecord
php artisan make:model TreatmentPlan
php artisan make:model PatientImage
php artisan make:model PeriodontalRecord
php artisan make:model SoftTissueRecord
php artisan make:model OrthodonticRecord
php artisan make:model Employee
php artisan make:model Appointment
php artisan make:model Invoice
php artisan make:model InvoiceTreatment
php artisan make:model InvoiceOtherCharge
php artisan make:model PaymentTransaction
php artisan make:model Payroll
php artisan make:model AttendanceRecord
php artisan make:model InventoryItem
php artisan make:model LaboratoryOrder
php artisan make:model LeaveRequest
php artisan make:model StaffAdvance
php artisan make:model Expense
php artisan make:model ChartData
php artisan make:model RolePermission

# Create Controllers
echo "Creating controllers..."
php artisan make:controller AuthController
php artisan make:controller PatientController
php artisan make:controller AppointmentController
php artisan make:controller InvoiceController
php artisan make:controller EmployeeController
php artisan make:controller ManagementController

echo "All models and controllers created successfully!"