<?php

namespace Database\Seeders;

use App\Models\LabService;
use App\Models\LabServicePrice;
use Illuminate\Database\Seeder;

class LabServicePriceSeeder extends Seeder
{
    public function run(): void
    {
        $labs = [
            ['name' => 'Premier Lab', 'prices' => [
                'Crown' => 5000,
                'Bridge' => 8000,
                'Denture' => 15000,
                'Implant' => 20000,
                'Veneer' => 3000,
                'Inlay' => 2500,
                'Onlay' => 3500,
                'Filling' => 1500,
            ]],
            ['name' => 'Standard Lab', 'prices' => [
                'Crown' => 4000,
                'Bridge' => 6500,
                'Denture' => 12000,
                'Implant' => 16000,
                'Veneer' => 2500,
                'Inlay' => 2000,
                'Onlay' => 2800,
                'Filling' => 1000,
            ]],
            ['name' => 'Economy Lab', 'prices' => [
                'Crown' => 3000,
                'Bridge' => 5000,
                'Denture' => 10000,
                'Implant' => 12000,
                'Veneer' => 1800,
                'Inlay' => 1500,
                'Onlay' => 2000,
                'Filling' => 800,
            ]],
        ];

        foreach ($labs as $lab) {
            $services = LabService::whereIn('service_name', array_keys($lab['prices']))->get();

            foreach ($services as $service) {
                LabServicePrice::firstOrCreate(
                    [
                        'lab_service_id' => $service->id,
                        'lab' => $lab['name'],
                        'branch_id' => $service->branch_id,
                        
                    ],
                    [
                        'base_price' => $lab['prices'][$service->service_name],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
