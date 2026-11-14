
<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'id' => 1,
                'name' => 'RUBAGA DENTAL SURGERY',
                'address' => 'Rubaga',
            ],
        ];

        foreach ($branches as $branch) {
            Branch::updateOrCreate(
                ['id' => $branch['id']],
                $branch
            );
        }
    }
}
