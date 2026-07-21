<?php

namespace Database\Seeders;

use App\Models\Treatment;
use Illuminate\Database\Seeder;

class TreatmentSeeder extends Seeder
{
    public function run()
    {
        $treatments = [
            ['treatment_code' => 'D0120', 'procedure' => 'Regular Checkup & Cleaning', 'cost' => 75, 'category' => 'Preventive'],
            ['treatment_code' => 'D2391', 'procedure' => 'Composite Filling', 'cost' => 250, 'category' => 'Restorative'],
            ['treatment_code' => 'D2140', 'procedure' => 'Amalgam Filling', 'cost' => 200, 'category' => 'Restorative'],
            ['treatment_code' => 'D3330', 'procedure' => 'Root Canal Therapy', 'cost' => 1500, 'category' => 'Restorative'],
            ['treatment_code' => 'D7140', 'procedure' => 'Tooth Extraction', 'cost' => 300, 'category' => 'Surgical'],
            ['treatment_code' => 'D2740', 'procedure' => 'Dental Crown', 'cost' => 1200, 'category' => 'Restorative'],
            ['treatment_code' => 'D6240', 'procedure' => 'Dental Bridge', 'cost' => 3500, 'category' => 'Restorative'],
            ['treatment_code' => 'D6010', 'procedure' => 'Dental Implant', 'cost' => 4000, 'category' => 'Surgical'],
            ['treatment_code' => 'D6011', 'procedure' => 'Single Tooth Implant', 'cost' => 4000, 'category' => 'Surgical'],
            ['treatment_code' => 'D6068', 'procedure' => 'Implant-Supported Bridge', 'cost' => 8000, 'category' => 'Surgical'],
            ['treatment_code' => 'D6110', 'procedure' => 'All-on-4 Implants', 'cost' => 25000, 'category' => 'Surgical'],
            ['treatment_code' => 'D9972', 'procedure' => 'Teeth Whitening', 'cost' => 450, 'category' => 'Cosmetic'],
            ['treatment_code' => 'D2962', 'procedure' => 'Veneers', 'cost' => 1800, 'category' => 'Cosmetic'],
            ['treatment_code' => 'D4341', 'procedure' => 'Periodontal Scaling and Root Planing', 'cost' => 800, 'category' => 'Preventive'],
            ['treatment_code' => 'D5110', 'procedure' => 'Dentures (Full)', 'cost' => 2500, 'category' => 'Restorative'],
            ['treatment_code' => 'D5211', 'procedure' => 'Dentures (Partial)', 'cost' => 1500, 'category' => 'Restorative'],
            ['treatment_code' => 'D0150', 'procedure' => 'Consultation', 'cost' => 100, 'category' => 'Other'],
            ['treatment_code' => 'D0140', 'procedure' => 'Emergency Exam', 'cost' => 150, 'category' => 'Other'],
        ];

        foreach ($treatments as $treatment) {
            Treatment::updateOrCreate(
                ['procedure' => $treatment['procedure']],
                $treatment
            );
        }
    }
}