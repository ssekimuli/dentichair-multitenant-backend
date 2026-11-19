<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DentalDiagnosisSeeder extends Seeder
{
    public function run(): void
    {
        $diagnoses = [
            'Dental Caries' => [
                'Dental caries',
                'Deep dental caries',
                'Recurrent dental caries',
                'Root caries',
                'Early childhood caries',
                'Arrested dental caries',
                'Rampant caries',
                'Enamel caries',
                'Dentin caries',
                'Pulpal involvement due to caries',
            ],

            'Pulpal Diseases' => [
                'Reversible pulpitis',
                'Irreversible pulpitis',
                'Symptomatic irreversible pulpitis',
                'Asymptomatic irreversible pulpitis',
                'Pulp necrosis',
                'Pulp degeneration',
                'Pulp calcification',
                'Pulpal hyperplasia (Pulp polyp)',
                'Previously treated pulp',
                'Previously initiated endodontic treatment',
            ],

            'Periapical Diseases' => [
                'Symptomatic apical periodontitis',
                'Asymptomatic apical periodontitis',
                'Acute apical abscess',
                'Chronic apical abscess',
                'Periapical granuloma',
                'Periapical cyst',
                'Periapical radiolucency',
                'Condensing osteitis',
                'Apical periodontitis',
                'Phoenix abscess',
            ],

            'Periodontal Diseases' => [
                'Gingivitis',
                'Plaque-induced gingivitis',
                'Acute gingivitis',
                'Chronic gingivitis',
                'Periodontitis',
                'Stage I periodontitis',
                'Stage II periodontitis',
                'Stage III periodontitis',
                'Stage IV periodontitis',
                'Necrotizing gingivitis',
                'Necrotizing periodontitis',
                'Gingival recession',
                'Periodontal abscess',
                'Periodontal pocket',
            ],

            'Tooth Disorders' => [
                'Tooth fracture',
                'Crown fracture',
                'Root fracture',
                'Cracked tooth syndrome',
                'Tooth wear',
                'Attrition',
                'Abrasion',
                'Erosion',
                'Tooth mobility',
                'Tooth discoloration',
                'Tooth sensitivity',
                'Hypercementosis',
                'Tooth ankylosis',
                'External root resorption',
                'Internal root resorption',
            ],

            'Oral Mucosal Diseases' => [
                'Oral candidiasis',
                'Oral lichen planus',
                'Recurrent aphthous stomatitis',
                'Oral leukoplakia',
                'Oral erythroplakia',
                'Geographic tongue',
                'Oral herpes simplex',
                'Angular cheilitis',
                'Traumatic ulcer',
                'Oral mucositis',
                'Oral submucous fibrosis',
                'Oral pemphigus',
                'Oral pemphigoid',
                'Oral pigmentation',
            ],

            'Dental Infections and Abscesses' => [
                'Dental abscess',
                'Periapical abscess',
                'Periodontal abscess',
                'Gingival abscess',
                'Pericoronitis',
                'Odontogenic cellulitis',
                'Facial space infection',
                "Ludwig's angina",
                'Osteomyelitis of the jaw',
                'Dry socket (Alveolar osteitis)',
            ],

            'Orthodontic Conditions' => [
                'Dental crowding',
                'Dental spacing',
                'Increased overjet',
                'Deep bite',
                'Open bite',
                'Crossbite',
                'Anterior crossbite',
                'Posterior crossbite',
                'Class I malocclusion',
                'Class II malocclusion',
                'Class III malocclusion',
                'Midline deviation',
                'Impacted teeth',
                'Ectopic eruption',
                'Hypodontia',
            ],

            'Developmental Dental Conditions' => [
                'Partial edentulism',
                'Complete edentulism',
                'Congenitally missing teeth',
                'Supernumerary teeth',
                'Impacted tooth',
                'Retained deciduous tooth',
                'Delayed eruption',
                'Enamel hypoplasia',
                'Amelogenesis imperfecta',
                'Dentinogenesis imperfecta',
                'Dens invaginatus',
                'Dens evaginatus',
                'Dental fluorosis',
                'Fusion',
                'Gemination',
            ],

            'Oral and Maxillofacial Conditions' => [
                'Temporomandibular joint disorder',
                'Bruxism',
                'Trismus',
                'TMJ dislocation',
                'TMJ arthritis',
                'Oral cyst',
                'Odontogenic keratocyst',
                'Dentigerous cyst',
                'Radicular cyst',
                'Jaw fracture',
                'Mandibular osteomyelitis',
                'Odontogenic maxillary sinusitis',
            ],

            'Prosthodontic Conditions' => [
                'Partial tooth loss',
                'Complete tooth loss',
                'Failed dental restoration',
                'Fractured denture',
                'Ill-fitting denture',
                'Loss of denture retention',
                'Failed dental crown',
                'Failed dental bridge',
                'Dental implant failure',
                'Peri-implantitis',
                'Peri-implant mucositis',
            ],

            'Other Dental Conditions' => [
                'Halitosis',
                'Dentin hypersensitivity',
                'Food impaction',
                'Occlusal trauma',
                'Dental trauma',
                'Avulsed tooth',
                'Luxated tooth',
                'Intruded tooth',
                'Extruded tooth',
                'Alveolar bone loss',
            ],
        ];

        $now = now();
        $records = [];

        foreach ($diagnoses as $category => $names) {
            foreach ($names as $name) {
                $records[] = [
                    'name' => $name,
                    'category' => $category,
                    'icd_code' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Avoid inserting duplicate diagnoses when seeder runs again.
        $existing = DB::table('diagnoses')
            ->select('name', 'category')
            ->get()
            ->map(fn ($item) => $item->category . '|' . $item->name)
            ->flip();

        $records = array_filter($records, function ($record) use ($existing) {
            return !isset($existing[$record['category'] . '|' . $record['name']]);
        });

        foreach (array_chunk(array_values($records), 100) as $chunk) {
            DB::table('diagnoses')->insert($chunk);
        }
    }
}
