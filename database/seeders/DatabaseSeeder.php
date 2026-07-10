<?php

namespace Database\Seeders;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            BranchSeeder::class,
            TreatmentSeeder::class,
            EmployeeSeeder::class,
            // PatientSeeder::class,
            // PeriodontalSeeder::class,
            // OdontogramSeeder::class,
            // AppointmentSeeder::class,
            // InvoiceSeeder::class,
            ManagementSeeder::class,
        ]);
    }
}