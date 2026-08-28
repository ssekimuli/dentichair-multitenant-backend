<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LabService;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabServiceFactory extends Factory
{
    protected $model = LabService::class;

    public function definition(): array
    {
        return [
            'service_name' => $this->faker->word(),
            'category' => $this->faker->word(),
            'is_active' => true,
            'branch_id' => Branch::factory(),
        ];
    }
}
