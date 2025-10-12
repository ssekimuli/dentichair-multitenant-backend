<?php

namespace Database\Seeders;

use App\Models\Patient;
use Illuminate\Database\Seeder;

class PatientSeeder extends Seeder
{
    public function run()
    {
        $patients = [
            [
                'id' => 1,
                'name' => 'John Doe',
                'avatar' => 'https://picsum.photos/seed/p1/200/200',
                'email' => 'john.doe@example.com',
                'date_of_birth' => '1985-05-20',
                'last_visit' => '2024-05-10',
                'status' => 'Active',
                'branch_id' => 1, // Changed to numeric
                'history' => 'Patient has a history of grinding teeth (bruxism). Regular check-ups every 6 months. No known allergies. Previous treatments include multiple fillings and one extraction.',
            ],
            [
                'id' => 2,
                'name' => 'Jane Smith',
                'avatar' => 'https://picsum.photos/seed/p2/200/200',
                'email' => 'jane.smith@example.com',
                'date_of_birth' => '1992-08-15',
                'last_visit' => '2024-04-22',
                'status' => 'Active',
                'branch_id' => 2, // Changed to numeric
                'history' => 'Good oral hygiene. No major issues reported. Undergoing orthodontic treatment.',
            ],
            [
                'id' => 3,
                'name' => 'Peter Jones',
                'avatar' => 'https://picsum.photos/seed/p3/200/200',
                'email' => 'peter.jones@example.com',
                'date_of_birth' => '1978-11-30',
                'last_visit' => '2023-12-15',
                'status' => 'Inactive',
                'branch_id' => 1, // Changed to numeric
                'history' => 'Requires follow-up for periodontal scaling.',
            ],
            [
                'id' => 4,
                'name' => 'Mike Williams',
                'avatar' => 'https://picsum.photos/seed/p4/200/200',
                'email' => 'mike.williams@example.com',
                'date_of_birth' => '1990-09-01',
                'last_visit' => '2024-07-15',
                'status' => 'Active',
                'branch_id' => 2, // Changed to numeric
                'history' => 'New patient.',
            ],
            [
                'id' => 5,
                'name' => 'Sarah Brown',
                'avatar' => 'https://picsum.photos/seed/p5/200/200',
                'email' => 'sarah.brown@example.com',
                'date_of_birth' => '1988-02-25',
                'last_visit' => '2024-07-14',
                'status' => 'Active',
                'branch_id' => 1, // Changed to numeric
                'history' => 'Regular patient.',
            ],
        ];

        foreach ($patients as $patient) {
            Patient::create([
                ...$patient,
                'occlusal_records' => [
                    'overbite' => rand(1, 3),
                    'overjet' => rand(1, 3),
                    'crossbite' => ['None', 'Anterior', 'Posterior'][rand(0, 2)],
                    'openbite' => ['None', 'Anterior', 'Posterior'][rand(0, 2)],
                    'malocclusion' => ['Class I', 'Class II', 'Class III'][rand(0, 2)],
                ],
            ]);
        }
    }
}