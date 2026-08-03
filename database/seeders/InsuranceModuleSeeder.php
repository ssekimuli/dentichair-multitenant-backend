<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InsuranceCompany;
use App\Models\InsurancePackage;
use App\Models\InsurancePackageProcedure;
use App\Models\Treatment; // Use Treatment as Procedure equivalent

class InsuranceModuleSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure we have some basic dental treatments first
        $consultation = Treatment::firstOrCreate(
            ['treatment_code' => 'D0120'], 
            ['treatment_name' => 'Periodic Oral Evaluation', 'cost' => 50000]
        );
        $cleaning = Treatment::firstOrCreate(
            ['treatment_code' => 'D1110'], 
            ['treatment_name' => 'Prophylaxis - Adult (Cleaning)', 'cost' => 120000]
        );
        $extraction = Treatment::firstOrCreate(
            ['treatment_code' => 'D7140'], 
            ['treatment_name' => 'Extraction - Erupted Tooth', 'cost' => 80000]
        );
        $rootCanal = Treatment::firstOrCreate(
            ['treatment_code' => 'D3310'], 
            ['treatment_name' => 'Root Canal - Anterior', 'cost' => 450000]
        );
        
        // 2. Create Jubilee Insurance
        $jubilee = InsuranceCompany::firstOrCreate(
            ['code' => 'JUB'],
            [
                'name' => 'Jubilee Insurance',
                'email' => 'claims@jubilee.com',
                'phone' => '+256 800 123456',
                'address' => 'Jubilee Insurance Centre, Kampala',
                'status' => 'active'
            ]
        );

        $jubileePremium = InsurancePackage::firstOrCreate(
            ['insurance_company_id' => $jubilee->id, 'code' => 'JUB-PREM-CORP'],
            [
                'name' => 'Jubilee Premium Corporate',
                'default_coverage_percentage' => 100,
                'default_annual_limit' => 5000000,
                'requires_pre_authorization' => false,
            ]
        );

        $jubileeBasic = InsurancePackage::firstOrCreate(
            ['insurance_company_id' => $jubilee->id, 'code' => 'JUB-BASIC'],
            [
                'name' => 'Jubilee Basic Individual',
                'default_coverage_percentage' => 80,
                'default_annual_limit' => 1000000,
                'requires_pre_authorization' => true,
            ]
        );

        // 3. Map Procedure Coverages
        foreach ([$consultation, $cleaning, $extraction, $rootCanal] as $proc) {
            InsurancePackageProcedure::firstOrCreate([
                'insurance_package_id' => $jubileePremium->id,
                'procedure_id' => $proc->id,
            ], [
                'coverage_type' => 'percentage',
                'coverage_value' => 100,
            ]);
        }

        InsurancePackageProcedure::firstOrCreate(
            ['insurance_package_id' => $jubileeBasic->id, 'procedure_id' => $consultation->id],
            ['coverage_type' => 'percentage', 'coverage_value' => 80]
        );
        InsurancePackageProcedure::firstOrCreate(
            ['insurance_package_id' => $jubileeBasic->id, 'procedure_id' => $rootCanal->id],
            [
                'coverage_type' => 'percentage', 
                'coverage_value' => 80, 
                'maximum_covered_amount' => 300000, 
                'requires_pre_authorization' => true
            ]
        );
    }
}
