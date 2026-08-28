<?php

namespace Database\Seeders;

use App\Models\LabService;
use App\Models\Branch;
use Illuminate\Database\Seeder;

class LabServiceSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        $services = [
            ['service_name' => 'Crown', 'category' => 'Prosthodontics'],
            ['service_name' => 'Bridge', 'category' => 'Prosthodontics'],
            ['service_name' => 'Denture', 'category' => 'Prosthodontics'],
            ['service_name' => 'Implant', 'category' => 'Implantology'],
            ['service_name' => 'Veneer', 'category' => 'Cosmetic'],
            ['service_name' => 'Inlay', 'category' => 'Restorative'],
            ['service_name' => 'Onlay', 'category' => 'Restorative'],
            ['service_name' => 'Filling', 'category' => 'Restorative'],
        ];

        foreach ($branches as $branch) {
            foreach ($services as $service) {
                LabService::firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'service_name' => $service['service_name'],
                    ],
                    [
                        'category' => $service['category'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
