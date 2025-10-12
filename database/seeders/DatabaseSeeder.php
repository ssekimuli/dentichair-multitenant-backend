<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\Employee;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Treatment;
use App\Models\Payroll;
use App\Models\InventoryItem;
use App\Models\LaboratoryOrder;
use App\Models\LeaveRequest;
use App\Models\StaffAdvance;
use App\Models\Expense;
use App\Models\ChartData;
use App\Models\DentalRecord;
use App\Models\PeriodontalRecord;
use App\Models\TreatmentPlan;
use App\Models\PatientImage;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            BranchSeeder::class,
            TreatmentSeeder::class,
            EmployeeSeeder::class,
            PatientSeeder::class,
            PeriodontalSeeder::class,
            OdontogramSeeder::class,
            AppointmentSeeder::class,
            InvoiceSeeder::class,
            ManagementSeeder::class,
        ]);
    }
}