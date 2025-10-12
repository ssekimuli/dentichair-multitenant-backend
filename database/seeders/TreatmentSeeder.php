<?php

namespace Database\Seeders;

use App\Models\Treatment;
use Illuminate\Database\Seeder;

class TreatmentSeeder extends Seeder
{
    public function run()
    {
        $treatments = [
            ['procedure' => 'Regular Checkup & Cleaning', 'cost' => 75],
            ['procedure' => 'Composite Filling', 'cost' => 250],
            ['procedure' => 'Amalgam Filling', 'cost' => 200],
            ['procedure' => 'Root Canal Therapy', 'cost' => 1500],
            ['procedure' => 'Tooth Extraction', 'cost' => 300],
            ['procedure' => 'Dental Crown', 'cost' => 1200],
            ['procedure' => 'Dental Bridge', 'cost' => 3500],
            ['procedure' => 'Dental Implant', 'cost' => 4000],
            ['procedure' => 'Single Tooth Implant', 'cost' => 4000],
            ['procedure' => 'Implant-Supported Bridge', 'cost' => 8000],
            ['procedure' => 'All-on-4 Implants', 'cost' => 25000],
            ['procedure' => 'Teeth Whitening', 'cost' => 450],
            ['procedure' => 'Veneers', 'cost' => 1800],
            ['procedure' => 'Periodontal Scaling and Root Planing', 'cost' => 800],
            ['procedure' => 'Dentures (Full)', 'cost' => 2500],
            ['procedure' => 'Dentures (Partial)', 'cost' => 1500],
            ['procedure' => 'Consultation', 'cost' => 100],
            ['procedure' => 'Emergency Exam', 'cost' => 150],
        ];

        foreach ($treatments as $treatment) {
            Treatment::create($treatment);
        }
    }
}