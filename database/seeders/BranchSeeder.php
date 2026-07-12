<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run()
    {
        $branches = [
            ['id' => 1, 'name' => 'Downtown Clinic', 'address' => '123 Main St, Cityville'],
            ['id' => 2, 'name' => 'Uptown Dental', 'address' => '456 Oak Ave, Townburg'],
            ['id' => 3, 'name' => 'Westside Dental Center', 'address' => '789 Pine Rd, Westside'],
        ];

        foreach ($branches as $branch) {
            Branch::updateOrCreate(['id' => $branch['id']], $branch);
        }
    }
}