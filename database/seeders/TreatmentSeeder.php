<?php

namespace Database\Seeders;

use App\Models\Treatment;
use Illuminate\Database\Seeder;

class TreatmentSeeder extends Seeder
{
    public function run()
    {
        $treatments = [
            ['procedure' => 'Regular Checkup & Cleaning', 'cost' => 75, 'category' => 'Preventive'],
            ['procedure' => 'Composite Filling', 'cost' => 250, 'category' => 'Restorative'],
            ['procedure' => 'Amalgam Filling', 'cost' => 200, 'category' => 'Restorative'],
            ['procedure' => 'Root Canal Therapy', 'cost' => 1500, 'category' => 'Restorative'],
            ['procedure' => 'Tooth Extraction', 'cost' => 300, 'category' => 'Surgical'],
            ['procedure' => 'Dental Crown', 'cost' => 1200, 'category' => 'Restorative'],
            ['procedure' => 'Dental Bridge', 'cost' => 3500, 'category' => 'Restorative'],
            ['procedure' => 'Dental Implant', 'cost' => 4000, 'category' => 'Surgical'],
            ['procedure' => 'Single Tooth Implant', 'cost' => 4000, 'category' => 'Surgical'],
            ['procedure' => 'Implant-Supported Bridge', 'cost' => 8000, 'category' => 'Surgical'],
            ['procedure' => 'All-on-4 Implants', 'cost' => 25000, 'category' => 'Surgical'],
            ['procedure' => 'Teeth Whitening', 'cost' => 450, 'category' => 'Cosmetic'],
            ['procedure' => 'Veneers', 'cost' => 1800, 'category' => 'Cosmetic'],
            ['procedure' => 'Periodontal Scaling and Root Planing', 'cost' => 800, 'category' => 'Preventive'],
            ['procedure' => 'Dentures (Full)', 'cost' => 2500, 'category' => 'Restorative'],
            ['procedure' => 'Dentures (Partial)', 'cost' => 1500, 'category' => 'Restorative'],
            ['procedure' => 'Consultation', 'cost' => 100, 'category' => 'Other'],
            ['procedure' => 'Emergency Exam', 'cost' => 150, 'category' => 'Other'],
        ];

        foreach ($treatments as $treatment) {
            Treatment::updateOrCreate(
                ['procedure' => $treatment['procedure']],
                $treatment
            );
        }
    }
}