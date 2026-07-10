<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'role' => 'Dentist',
            'role_id' => 4,
            'status' => 'Active',
            'salary' => 5000.00,
            'hire_date' => now()->format('Y-m-d'),
            'password' => Hash::make('password'),
            'branch_id' => fn () => Branch::first()?->id ?? Branch::create([
                'name' => 'Default Branch',
                'address' => '123 St'
            ])->id,
        ];
    }
}
