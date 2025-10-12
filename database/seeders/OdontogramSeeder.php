<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\DentalRecord;
use App\Models\PeriodontalRecord;
use App\Models\TreatmentPlan;
use App\Models\PatientImage;
use Illuminate\Database\Seeder;

class OdontogramSeeder extends Seeder
{
    public function run()
    {
        $patients = Patient::all();

        foreach ($patients as $patient) {
            $this->createIndividualDentalRecords($patient);
            $this->createTreatmentPlans($patient);
        }
    }

    private function createIndividualDentalRecords($patient)
    {
        // Each patient gets their own set of dental records, one tooth at a time
        $patientDentalData = [
            '1' => [ // John Doe - Complete dental chart
                ['tooth_id' => 1, 'condition' => 'Missing', 'notes' => 'Impacted and surgically removed in 2018.'],
                ['tooth_id' => 2, 'condition' => 'Crown', 'notes' => 'Porcelain crown placed due to large fracture.'],
                ['tooth_id' => 3, 'condition' => 'Filling', 'notes' => 'Large MOD amalgam filling.'],
                ['tooth_id' => 4, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 5, 'condition' => 'Filling', 'notes' => 'Occlusal composite.'],
                ['tooth_id' => 6, 'condition' => 'Crown', 'notes' => 'Full coverage crown on root canal treated tooth.'],
                ['tooth_id' => 7, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 8, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 9, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 10, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 11, 'condition' => 'Filling', 'notes' => 'Lingual composite.'],
                ['tooth_id' => 12, 'condition' => 'Caries', 'notes' => 'Mesial caries detected on X-ray.'],
                ['tooth_id' => 13, 'condition' => 'Filling', 'notes' => 'Occlusal amalgam.'],
                ['tooth_id' => 14, 'condition' => 'Crown', 'notes' => 'Zirconia crown placed 2 years ago.'],
                ['tooth_id' => 15, 'condition' => 'Filling', 'notes' => 'MOD composite.'],
                ['tooth_id' => 16, 'condition' => 'Missing', 'notes' => 'Prophylactically removed.'],
                ['tooth_id' => 17, 'condition' => 'Missing', 'notes' => 'Prophylactically removed.'],
                ['tooth_id' => 18, 'condition' => 'Filling', 'notes' => 'Occlusal amalgam.'],
                ['tooth_id' => 19, 'condition' => 'Crown', 'notes' => 'Root canal treated with PFM crown.'],
                ['tooth_id' => 20, 'condition' => 'Caries', 'notes' => 'Recurrent caries at crown margin.'],
                ['tooth_id' => 21, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 22, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 23, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 24, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 25, 'condition' => 'Fracture', 'notes' => 'Chipped incisal edge.'],
                ['tooth_id' => 26, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 27, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 28, 'condition' => 'Filling', 'notes' => 'Buccal pit composite.'],
                ['tooth_id' => 29, 'condition' => 'Filling', 'notes' => 'MOD amalgam.'],
                ['tooth_id' => 30, 'condition' => 'Caries', 'notes' => 'Deep occlusal caries, requires RCT evaluation.'],
                ['tooth_id' => 31, 'condition' => 'Filling', 'notes' => 'DO composite.'],
                ['tooth_id' => 32, 'condition' => 'Missing', 'notes' => 'Impacted and surgically removed in 2018.'],
            ],
            '2' => [ // Jane Smith - Orthodontic patient
                ['tooth_id' => 8, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 9, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 24, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 25, 'condition' => 'Healthy', 'notes' => ''],
                ['tooth_id' => 7, 'condition' => 'Filling', 'notes' => 'Small distal composite.'],
                ['tooth_id' => 10, 'condition' => 'Filling', 'notes' => 'Small mesial composite.'],
            ],
            '3' => [ // Peter Jones - Multiple extractions
                ['tooth_id' => 3, 'condition' => 'Missing', 'notes' => 'Extracted due to caries.'],
                ['tooth_id' => 14, 'condition' => 'Missing', 'notes' => 'Extracted due to periodontal disease.'],
                ['tooth_id' => 19, 'condition' => 'Missing', 'notes' => 'Extracted due to fracture.'],
                ['tooth_id' => 30, 'condition' => 'Missing', 'notes' => 'Extracted due to periodontal disease.'],
                ['tooth_id' => 2, 'condition' => 'Crown', 'notes' => 'PFM crown.'],
                ['tooth_id' => 31, 'condition' => 'Crown', 'notes' => 'Full gold crown.'],
            ],
            '4' => [ // Mike Williams - Minimal work
                ['tooth_id' => 6, 'condition' => 'Filling', 'notes' => 'Small occlusal filling.'],
                ['tooth_id' => 11, 'condition' => 'Filling', 'notes' => 'Mesial composite.'],
                ['tooth_id' => 29, 'condition' => 'Filling', 'notes' => 'Occlusal composite.'],
                ['tooth_id' => 30, 'condition' => 'Filling', 'notes' => 'DO amalgam.'],
            ],
            '5' => [ // Sarah Brown - Anterior issues
                ['tooth_id' => 7, 'condition' => 'Fracture', 'notes' => 'Incisal edge fracture.'],
                ['tooth_id' => 8, 'condition' => 'Fracture', 'notes' => 'Mesial incisal fracture.'],
                ['tooth_id' => 9, 'condition' => 'Fracture', 'notes' => 'Distal incisal fracture.'],
                ['tooth_id' => 10, 'condition' => 'Fracture', 'notes' => 'Incisal edge fracture.'],
                ['tooth_id' => 25, 'condition' => 'Fracture', 'notes' => 'Chipped incisal edge.'],
                ['tooth_id' => 26, 'condition' => 'Fracture', 'notes' => 'Chipped incisal edge.'],
            ]
        ];

        if (isset($patientDentalData[$patient->id])) {
            foreach ($patientDentalData[$patient->id] as $toothData) {
                // This simulates the API call: PUT /api/patients/{id}/dental-records
                DentalRecord::updateOrCreate(
                    [
                        'patient_id' => $patient->id,
                        'tooth_id' => $toothData['tooth_id'],
                    ],
                    [
                        'condition' => $toothData['condition'],
                        'notes' => $toothData['notes'],
                    ]
                );
            }
        }

        // For teeth not specified, set them as Healthy by default
        $this->setDefaultHealthyTeeth($patient, $patientDentalData[$patient->id] ?? []);
    }

    private function setDefaultHealthyTeeth($patient, $existingRecords)
    {
        $allTeeth = range(1, 32);
        $existingToothIds = array_column($existingRecords, 'tooth_id');
        
        $teethToCreate = array_diff($allTeeth, $existingToothIds);
        
        foreach ($teethToCreate as $toothId) {
            DentalRecord::updateOrCreate(
                [
                    'patient_id' => $patient->id,
                    'tooth_id' => $toothId,
                ],
                [
                    'condition' => 'Healthy',
                    'notes' => '',
                ]
            );
        }
    }

    private function createTreatmentPlans($patient)
    {
        $treatmentData = [
            '1' => [ // John Doe - Needs multiple treatments
                [
                    'procedure' => 'Filling for #12',
                    'tooth' => 12,
                    'status' => 'Proposed',
                    'cost' => 250,
                    'date' => '2024-08-01',
                    'invoice_id' => null
                ],
                [
                    'procedure' => 'Root Canal Treatment for #30',
                    'tooth' => 30,
                    'status' => 'Proposed',
                    'cost' => 1500,
                    'date' => '2024-08-15',
                    'invoice_id' => null
                ],
                [
                    'procedure' => 'Crown for #25',
                    'tooth' => 25,
                    'status' => 'Proposed',
                    'cost' => 1200,
                    'date' => '2024-09-01',
                    'invoice_id' => null
                ]
            ],
            '2' => [ // Jane Smith - Orthodontic follow-up
                [
                    'procedure' => 'Orthodontic Adjustment',
                    'tooth' => null,
                    'status' => 'Proposed',
                    'cost' => 200,
                    'date' => '2024-08-10',
                    'invoice_id' => null
                ]
            ],
            '5' => [ // Sarah Brown - Cosmetic work
                [
                    'procedure' => 'Composite Bonding for Anterior Teeth',
                    'tooth' => null,
                    'status' => 'Proposed',
                    'cost' => 1200,
                    'date' => '2024-08-20',
                    'invoice_id' => null
                ]
            ]
        ];

        if (isset($treatmentData[$patient->id])) {
            foreach ($treatmentData[$patient->id] as $treatment) {
                TreatmentPlan::updateOrCreate(
                    [
                        'patient_id' => $patient->id,
                        'procedure' => $treatment['procedure'],
                        'tooth' => $treatment['tooth'],
                    ],
                    [
                        'status' => $treatment['status'],
                        'cost' => $treatment['cost'],
                        'date' => $treatment['date'],
                        'invoice_id' => $treatment['invoice_id'],
                    ]
                );
            }
        }
    }
}