<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run()
    {
        $branches = [
            ['id' => 'b1', 'name' => 'Downtown Clinic', 'address' => '123 Main St, Cityville'],
            ['id' => 'b2', 'name' => 'Uptown Dental', 'address' => '456 Oak Ave, Townburg'],
            ['id' => 'b3', 'name' => 'Westside Dental Center', 'address' => '789 Pine Rd, Westside'],
        ];

        foreach ($branches as $branch) {
            Branch::create($branch);
        }
    }
}