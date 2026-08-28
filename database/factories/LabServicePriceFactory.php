<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LabService;
use App\Models\LabServicePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabServicePriceFactory extends Factory
{
    protected $model = LabServicePrice::class;

    public function definition(): array
    {
        return [
            'lab_service_id' => LabService::factory(),
            'lab' => $this->faker->company(),
            'base_price' => $this->faker->randomFloat(2, 1000, 20000),
            'branch_id' => Branch::factory(),
            'is_active' => true,
        ];
    }
}
