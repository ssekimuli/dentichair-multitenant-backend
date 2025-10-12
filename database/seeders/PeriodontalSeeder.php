<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\DentalRecord;
use App\Models\PeriodontalRecord;
use App\Models\TreatmentPlan;
use App\Models\PatientImage;
use Illuminate\Database\Seeder;

class PeriodontalSeeder extends Seeder
{
    public function run()
    {
        $patients = Patient::all();

        foreach ($patients as $patient) {
            $this->createDentalRecords($patient);
            $this->createPeriodontalRecords($patient);
            $this->createTreatmentPlans($patient);
            $this->createPatientImages($patient);
        }
    }

    private function createDentalRecords($patient)
    {
        $dentalData = [
            '1' => [ // John Doe
                18 => ['Healthy', ''],
                17 => ['Filling', 'Amalgam filling from 2015.'],
                16 => ['Caries', 'Distal caries, requires attention.'],
                26 => ['Crown', 'Porcelain crown placed in 2020.'],
                36 => ['Missing', 'Extracted in 2010.'],
                48 => ['Fracture', 'Mesial-occlusal fracture.'],
            ],
            '2' => [ // Jane Smith
                8 => ['Healthy', 'No issues'],
                9 => ['Healthy', 'No issues'],
                24 => ['Healthy', 'No issues'],
                25 => ['Healthy', 'No issues'],
            ],
            '3' => [ // Peter Jones
                3 => ['Missing', 'Extracted due to caries'],
                14 => ['Caries', 'Occlusal caries'],
                19 => ['Filling', 'Composite filling'],
                30 => ['Crown', 'Full coverage crown'],
            ],
            '4' => [ // Mike Williams
                6 => ['Filling', 'Small occlusal filling'],
                11 => ['Filling', 'Mesial composite'],
                22 => ['Healthy', 'No issues'],
                27 => ['Healthy', 'No issues'],
            ],
            '5' => [ // Sarah Brown
                2 => ['Caries', 'Distal caries'],
                15 => ['Filling', 'Amalgam filling'],
                18 => ['Healthy', 'No issues'],
                31 => ['Crown', 'Zirconia crown'],
            ]
        ];

        if (isset($dentalData[$patient->id])) {
            foreach ($dentalData[$patient->id] as $toothId => $record) {
                DentalRecord::create([
                    'patient_id' => $patient->id,
                    'tooth_id' => $toothId,
                    'condition' => $record[0],
                    'notes' => $record[1],
                ]);
            }
        }
    }

    private function createPeriodontalRecords($patient)
    {
        $periodontalData = [
            '1' => [ // John Doe - Tooth 16
                [
                    'tooth_id' => 16,
                    'pockets' => [
                        'distobuccal' => 3,
                        'buccal' => 2,
                        'mesiobuccal' => 3,
                        'distolingual' => 3,
                        'lingual' => 2,
                        'mesiolingual' => 2
                    ],
                    'recession' => 1,
                    'mobility' => '0',
                    'furcation' => null,
                    'conditions' => [
                        'bleeding' => true,
                        'calculus' => false,
                        'plaque' => true
                    ]
                ],
                // John Doe - Tooth 36
                [
                    'tooth_id' => 36,
                    'pockets' => [
                        'distobuccal' => 4,
                        'buccal' => 3,
                        'mesiobuccal' => 4,
                        'distolingual' => 4,
                        'lingual' => 3,
                        'mesiolingual' => 3
                    ],
                    'recession' => 2,
                    'mobility' => '1',
                    'furcation' => 'I',
                    'conditions' => [
                        'bleeding' => true,
                        'calculus' => true,
                        'plaque' => true
                    ]
                ]
            ],
            '3' => [ // Peter Jones - Tooth 14
                [
                    'tooth_id' => 14,
                    'pockets' => [
                        'distobuccal' => 5,
                        'buccal' => 4,
                        'mesiobuccal' => 5,
                        'distolingual' => 4,
                        'lingual' => 3,
                        'mesiolingual' => 4
                    ],
                    'recession' => 2,
                    'mobility' => '1',
                    'furcation' => 'II',
                    'conditions' => [
                        'bleeding' => true,
                        'calculus' => true,
                        'plaque' => true
                    ]
                ],
                // Peter Jones - Tooth 19
                [
                    'tooth_id' => 19,
                    'pockets' => [
                        'distobuccal' => 3,
                        'buccal' => 3,
                        'mesiobuccal' => 4,
                        'distolingual' => 3,
                        'lingual' => 2,
                        'mesiolingual' => 3
                    ],
                    'recession' => 1,
                    'mobility' => '0',
                    'furcation' => null,
                    'conditions' => [
                        'bleeding' => false,
                        'calculus' => true,
                        'plaque' => false
                    ]
                ]
            ]
        ];

        if (isset($periodontalData[$patient->id])) {
            foreach ($periodontalData[$patient->id] as $recordData) {
                PeriodontalRecord::create([
                    'patient_id' => $patient->id,
                    'tooth_id' => $recordData['tooth_id'],
                    'pockets' => $recordData['pockets'],
                    'recession' => $recordData['recession'],
                    'mobility' => $recordData['mobility'],
                    'furcation' => $recordData['furcation'],
                    'conditions' => $recordData['conditions'],
                ]);
            }
        }
    }

    private function createTreatmentPlans($patient)
    {
        $treatmentData = [
            '1' => [ // John Doe
                [
                    'procedure' => 'Filling for #16',
                    'tooth' => 16,
                    'status' => 'Completed',
                    'cost' => 250,
                    'date' => '2024-05-10',
                    'invoice_id' => 'inv1'
                ],
                [
                    'procedure' => 'Crown for #48',
                    'tooth' => 48,
                    'status' => 'Proposed',
                    'cost' => 1200,
                    'date' => '2024-06-01',
                    'invoice_id' => null
                ],
                [
                    'procedure' => 'Regular Checkup',
                    'tooth' => null,
                    'status' => 'Completed',
                    'cost' => 75,
                    'date' => '2024-03-01',
                    'invoice_id' => 'inv4'
                ],
                [
                    'procedure' => 'Teeth Whitening',
                    'tooth' => null,
                    'status' => 'Completed',
                    'cost' => 450,
                    'date' => '2024-07-20',
                    'invoice_id' => null
                ]
            ],
            '2' => [ // Jane Smith
                [
                    'procedure' => 'Cleaning',
                    'tooth' => null,
                    'status' => 'Completed',
                    'cost' => 150,
                    'date' => '2024-04-22',
                    'invoice_id' => 'inv2'
                ]
            ],
            '4' => [ // Mike Williams
                [
                    'procedure' => 'Filling',
                    'tooth' => null,
                    'status' => 'Completed',
                    'cost' => 300,
                    'date' => '2024-07-15',
                    'invoice_id' => 'inv3'
                ]
            ]
        ];

        if (isset($treatmentData[$patient->id])) {
            foreach ($treatmentData[$patient->id] as $treatment) {
                TreatmentPlan::create([
                    'patient_id' => $patient->id,
                    'procedure' => $treatment['procedure'],
                    'tooth' => $treatment['tooth'],
                    'status' => $treatment['status'],
                    'cost' => $treatment['cost'],
                    'date' => $treatment['date'],
                    'invoice_id' => $treatment['invoice_id'],
                ]);
            }
        }
    }

    private function createPatientImages($patient)
    {
        $imageData = [
            '1' => [ // John Doe
                [
                    'url' => 'https://picsum.photos/seed/xray1/800/600',
                    'description' => 'Initial panoramic X-ray',
                    'date' => '2023-01-15'
                ],
                [
                    'url' => 'https://picsum.photos/seed/xray2/800/600',
                    'description' => 'Bitewing - Right Side',
                    'date' => '2023-01-15'
                ],
                [
                    'url' => 'https://picsum.photos/seed/xray3/800/600',
                    'description' => 'Periapical - Tooth #16',
                    'date' => '2024-05-08'
                ],
                [
                    'url' => 'https://picsum.photos/seed/xray4/800/600',
                    'description' => 'Post-treatment - Tooth #16',
                    'date' => '2024-05-10'
                ]
            ],
            '2' => [ // Jane Smith
                [
                    'url' => 'https://picsum.photos/seed/xray7/800/600',
                    'description' => 'Annual Bitewing',
                    'date' => '2024-04-22'
                ]
            ]
        ];

        if (isset($imageData[$patient->id])) {
            foreach ($imageData[$patient->id] as $image) {
                PatientImage::create([
                    'patient_id' => $patient->id,
                    'url' => $image['url'],
                    'description' => $image['description'],
                    'date' => $image['date'],
                ]);
            }
        }
    }
}