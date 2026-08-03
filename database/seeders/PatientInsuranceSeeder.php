<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Illuminate\Support\Str;

class PatientInsuranceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $patients = \App\Models\Patient::inRandomOrder()->take(10)->get();

        if ($patients->isEmpty()) {
            return;
        }

        $providers = ['BlueCross BlueShield', 'Aetna', 'Cigna', 'UnitedHealthcare', 'Humana'];
        $planTypes = ['HMO', 'PPO', 'EPO', 'POS'];

        foreach ($patients as $patient) {
            \App\Models\PatientInsurance::create([
                'patient_id' => $patient->id,
                'provider_name' => $providers[array_rand($providers)],
                'policy_number' => 'POL-' . strtoupper(Str::random(8)),
                'group_number' => 'GRP-' . rand(1000, 9999),
                'plan_type' => $planTypes[array_rand($planTypes)],
                'coverage_start' => now()->subMonths(rand(1, 12))->format('Y-m-d'),
                'coverage_end' => now()->addMonths(rand(1, 12))->format('Y-m-d'),
                'is_active' => true,
            ]);
        }
    }
}
