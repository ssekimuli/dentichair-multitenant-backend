<?php

namespace Database\Seeders;

use App\Models\Medicine;
use App\Models\Branch;
use Illuminate\Database\Seeder;

class MedicineSeeder extends Seeder
{
    public function run()
    {
        $branches = Branch::all();

        if ($branches->isEmpty()) {
            return;
        }

        $medicinesData = [
            [
                'name' => 'Paracetamol',
                'description' => 'Used to treat mild to moderate pain and reduce fever.',
                'category' => 'Analgesics',
                'units' => [
                    ['unit_name' => 'Tablet', 'price' => 0.20, 'stock_quantity' => 1000],
                    ['unit_name' => 'Box (20 Tabs)', 'price' => 3.50, 'stock_quantity' => 50],
                ]
            ],
            [
                'name' => 'Amoxicillin',
                'description' => 'Penicillin antibiotic used to treat bacterial infections.',
                'category' => 'Antibiotics',
                'units' => [
                    ['unit_name' => 'Capsule', 'price' => 1.50, 'stock_quantity' => 500],
                    ['unit_name' => 'Suspension Bottle', 'price' => 8.50, 'stock_quantity' => 30],
                ]
            ],
            [
                'name' => 'Ibuprofen',
                'description' => 'Nonsteroidal anti-inflammatory drug (NSAID) to reduce hormones that cause pain and inflammation.',
                'category' => 'Analgesics',
                'units' => [
                    ['unit_name' => 'Tablet', 'price' => 0.30, 'stock_quantity' => 800],
                    ['unit_name' => 'Syrup Bottle', 'price' => 6.00, 'stock_quantity' => 45],
                ]
            ],
            [
                'name' => 'Cetirizine',
                'description' => 'Antihistamine that reduces the natural chemical histamine in the body.',
                'category' => 'Antihistamines',
                'units' => [
                    ['unit_name' => 'Tablet', 'price' => 0.40, 'stock_quantity' => 600],
                    ['unit_name' => 'Syrup Bottle', 'price' => 7.00, 'stock_quantity' => 25],
                ]
            ],
            [
                'name' => 'Chlorhexidine Gluconate Mouthwash',
                'description' => 'Germicidal mouthwash that reduces bacteria in the mouth, used for treating gingivitis.',
                'category' => 'Antiseptics',
                'units' => [
                    ['unit_name' => 'Bottle (100ml)', 'price' => 12.00, 'stock_quantity' => 40],
                ]
            ]
        ];

        foreach ($branches as $branch) {
            foreach ($medicinesData as $med) {
                $medicine = Medicine::updateOrCreate(
                    [
                        'name' => $med['name'],
                        'branch_id' => $branch->id,
                    ],
                    [
                        'description' => $med['description'],
                        'category' => $med['category'],
                    ]
                );

                foreach ($med['units'] as $unit) {
                    $medicine->units()->updateOrCreate(
                        ['unit_name' => $unit['unit_name']],
                        [
                            'price' => $unit['price'],
                            'stock_quantity' => $unit['stock_quantity'],
                        ]
                    );
                }
            }
        }
    }
}
