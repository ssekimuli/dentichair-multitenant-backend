<?php

namespace Database\Seeders;

use App\Models\Treatment;
use Illuminate\Database\Seeder;

class TreatmentSeeder extends Seeder
{
    public function run()
    {
        $treatments = [
            ['treatment_code' => 'D0120', 'procedure' => 'Regular Checkup & Cleaning', 'cost' => 75, 'category' => 'Preventive'],
            ['treatment_code' => 'D2391', 'procedure' => 'Composite Filling', 'cost' => 250, 'category' => 'Restorative'],
            ['treatment_code' => 'D2140', 'procedure' => 'Amalgam Filling', 'cost' => 200, 'category' => 'Restorative'],
            ['treatment_code' => 'D3330', 'procedure' => 'Root Canal Therapy', 'cost' => 1500, 'category' => 'Restorative'],
            ['treatment_code' => 'D7140', 'procedure' => 'Tooth Extraction', 'cost' => 300, 'category' => 'Surgical'],
            ['treatment_code' => 'D2740', 'procedure' => 'Dental Crown', 'cost' => 1200, 'category' => 'Restorative'],
            ['treatment_code' => 'D6240', 'procedure' => 'Dental Bridge', 'cost' => 3500, 'category' => 'Restorative'],
            ['treatment_code' => 'D6010', 'procedure' => 'Dental Implant', 'cost' => 4000, 'category' => 'Surgical'],
            ['treatment_code' => 'D6011', 'procedure' => 'Single Tooth Implant', 'cost' => 4000, 'category' => 'Surgical'],
            ['treatment_code' => 'D6068', 'procedure' => 'Implant-Supported Bridge', 'cost' => 8000, 'category' => 'Surgical'],
            ['treatment_code' => 'D6110', 'procedure' => 'All-on-4 Implants', 'cost' => 25000, 'category' => 'Surgical'],
            ['treatment_code' => 'D9972', 'procedure' => 'Teeth Whitening', 'cost' => 450, 'category' => 'Cosmetic'],
            ['treatment_code' => 'D2962', 'procedure' => 'Veneers', 'cost' => 1800, 'category' => 'Cosmetic'],
            ['treatment_code' => 'D4341', 'procedure' => 'Periodontal Scaling and Root Planing', 'cost' => 800, 'category' => 'Preventive'],
            ['treatment_code' => 'D5110', 'procedure' => 'Dentures (Full)', 'cost' => 2500, 'category' => 'Restorative'],
            ['treatment_code' => 'D5211', 'procedure' => 'Dentures (Partial)', 'cost' => 1500, 'category' => 'Restorative'],
            ['treatment_code' => 'D0150', 'procedure' => 'Consultation', 'cost' => 100, 'category' => 'Other'],
            ['treatment_code' => 'D0140', 'procedure' => 'Emergency Exam', 'cost' => 150, 'category' => 'Other'],
            // Direct Restorations
            ['treatment_code' => 'REST01', 'procedure' => 'Composite (tooth-colored) fillings', 'cost' => 200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST02', 'procedure' => 'Amalgam (silver) fillings', 'cost' => 150, 'category' => 'Restorative'],
            ['treatment_code' => 'REST03', 'procedure' => 'Glass ionomer cement (GIC) fillings', 'cost' => 180, 'category' => 'Restorative'],
            ['treatment_code' => 'REST04', 'procedure' => 'Resin-modified glass ionomer (RMGIC) fillings', 'cost' => 190, 'category' => 'Restorative'],
            ['treatment_code' => 'REST05', 'procedure' => 'Temporary fillings', 'cost' => 80, 'category' => 'Restorative'],
            // Indirect Restorations
            ['treatment_code' => 'REST06', 'procedure' => 'Dental inlays', 'cost' => 800, 'category' => 'Restorative'],
            ['treatment_code' => 'REST07', 'procedure' => 'Dental onlays', 'cost' => 900, 'category' => 'Restorative'],
            ['treatment_code' => 'REST08', 'procedure' => 'Porcelain veneers', 'cost' => 1200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST09', 'procedure' => 'Composite veneers', 'cost' => 400, 'category' => 'Restorative'],
            // Crowns
            ['treatment_code' => 'REST10', 'procedure' => 'Full metal crowns', 'cost' => 1000, 'category' => 'Restorative'],
            ['treatment_code' => 'REST11', 'procedure' => 'Porcelain-fused-to-metal (PFM) crowns', 'cost' => 1100, 'category' => 'Restorative'],
            ['treatment_code' => 'REST12', 'procedure' => 'All-ceramic crowns', 'cost' => 1300, 'category' => 'Restorative'],
            ['treatment_code' => 'REST13', 'procedure' => 'Zirconia crowns', 'cost' => 1400, 'category' => 'Restorative'],
            ['treatment_code' => 'REST14', 'procedure' => 'E-max (lithium disilicate) crowns', 'cost' => 1500, 'category' => 'Restorative'],
            ['treatment_code' => 'REST15', 'procedure' => 'Stainless steel crowns', 'cost' => 300, 'category' => 'Restorative'],
            // Bridge Procedures
            ['treatment_code' => 'REST16', 'procedure' => 'Traditional dental bridge', 'cost' => 3000, 'category' => 'Restorative'],
            ['treatment_code' => 'REST17', 'procedure' => 'Cantilever bridge', 'cost' => 2500, 'category' => 'Restorative'],
            ['treatment_code' => 'REST18', 'procedure' => 'Maryland bridge (resin-bonded bridge)', 'cost' => 2000, 'category' => 'Restorative'],
            ['treatment_code' => 'REST19', 'procedure' => 'Implant-supported bridge', 'cost' => 5000, 'category' => 'Restorative'],
            // Implant Restorations
            ['treatment_code' => 'REST20', 'procedure' => 'Single implant crown', 'cost' => 1500, 'category' => 'Restorative'],
            ['treatment_code' => 'REST21', 'procedure' => 'Implant-supported overdenture', 'cost' => 6000, 'category' => 'Restorative'],
            ['treatment_code' => 'REST22', 'procedure' => 'Full-arch implant restoration (All-on-4/All-on-6)', 'cost' => 25000, 'category' => 'Restorative'],
            // Denture Restorations
            ['treatment_code' => 'REST24', 'procedure' => 'Complete dentures', 'cost' => 2000, 'category' => 'Restorative'],
            ['treatment_code' => 'REST25', 'procedure' => 'Partial dentures', 'cost' => 1200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST26', 'procedure' => 'Immediate dentures', 'cost' => 1500, 'category' => 'Restorative'],
            ['treatment_code' => 'REST27', 'procedure' => 'Flexible dentures', 'cost' => 1400, 'category' => 'Restorative'],
            ['treatment_code' => 'REST28', 'procedure' => 'Implant-retained dentures', 'cost' => 4500, 'category' => 'Restorative'],
            ['treatment_code' => 'REST29', 'procedure' => 'Denture relining', 'cost' => 300, 'category' => 'Restorative'],
            ['treatment_code' => 'REST30', 'procedure' => 'Denture rebasing', 'cost' => 400, 'category' => 'Restorative'],
            ['treatment_code' => 'REST31', 'procedure' => 'Denture repair', 'cost' => 150, 'category' => 'Restorative'],
            // Endodontic Restorations
            ['treatment_code' => 'REST32', 'procedure' => 'Core buildup', 'cost' => 250, 'category' => 'Restorative'],
            ['treatment_code' => 'REST33', 'procedure' => 'Post and core restoration', 'cost' => 350, 'category' => 'Restorative'],
            ['treatment_code' => 'REST34', 'procedure' => 'Crown after root canal treatment', 'cost' => 1200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST35', 'procedure' => 'Endocrown', 'cost' => 1000, 'category' => 'Restorative'],
            // Pediatric Restorations
            ['treatment_code' => 'REST36', 'procedure' => 'Composite restorations', 'cost' => 150, 'category' => 'Restorative'],
            ['treatment_code' => 'REST37', 'procedure' => 'Glass ionomer restorations', 'cost' => 120, 'category' => 'Restorative'],
            ['treatment_code' => 'REST38', 'procedure' => 'Strip crowns', 'cost' => 200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST39', 'procedure' => 'Hall technique crowns', 'cost' => 250, 'category' => 'Restorative'],
            // Cosmetic Restorations
            ['treatment_code' => 'REST41', 'procedure' => 'Direct composite bonding', 'cost' => 300, 'category' => 'Restorative'],
            ['treatment_code' => 'REST42', 'procedure' => 'Diastema (gap) closure', 'cost' => 400, 'category' => 'Restorative'],
            ['treatment_code' => 'REST43', 'procedure' => 'Tooth reshaping (enameloplasty)', 'cost' => 150, 'category' => 'Restorative'],
            // Tooth Reconstruction
            ['treatment_code' => 'REST46', 'procedure' => 'Cusp replacement', 'cost' => 300, 'category' => 'Restorative'],
            ['treatment_code' => 'REST47', 'procedure' => 'Fractured tooth restoration', 'cost' => 350, 'category' => 'Restorative'],
            ['treatment_code' => 'REST48', 'procedure' => 'Worn tooth rehabilitation', 'cost' => 500, 'category' => 'Restorative'],
            ['treatment_code' => 'REST49', 'procedure' => 'Full mouth rehabilitation', 'cost' => 20000, 'category' => 'Restorative'],
            ['treatment_code' => 'REST50', 'procedure' => 'Occlusal reconstruction', 'cost' => 5000, 'category' => 'Restorative'],
            // Repair and Replacement
            ['treatment_code' => 'REST51', 'procedure' => 'Filling replacement', 'cost' => 200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST52', 'procedure' => 'Crown replacement', 'cost' => 1200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST53', 'procedure' => 'Bridge replacement', 'cost' => 3500, 'category' => 'Restorative'],
            ['treatment_code' => 'REST54', 'procedure' => 'Veneer replacement', 'cost' => 1200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST55', 'procedure' => 'Composite restoration repair', 'cost' => 150, 'category' => 'Restorative'],
            ['treatment_code' => 'REST56', 'procedure' => 'Crown repair', 'cost' => 250, 'category' => 'Restorative'],
            // Additional Restorative Procedures
            ['treatment_code' => 'REST57', 'procedure' => 'Pin-retained restoration', 'cost' => 250, 'category' => 'Restorative'],
            ['treatment_code' => 'REST58', 'procedure' => 'Foundation restoration', 'cost' => 300, 'category' => 'Restorative'],
            ['treatment_code' => 'REST59', 'procedure' => 'Caries excavation and restoration', 'cost' => 200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST60', 'procedure' => 'Deep margin elevation', 'cost' => 250, 'category' => 'Restorative'],
            ['treatment_code' => 'REST61', 'procedure' => 'Indirect pulp capping before restoration', 'cost' => 100, 'category' => 'Restorative'],
            ['treatment_code' => 'REST62', 'procedure' => 'Direct pulp capping before restoration', 'cost' => 120, 'category' => 'Restorative'],
            ['treatment_code' => 'REST63', 'procedure' => 'Temporary crown placement', 'cost' => 150, 'category' => 'Restorative'],
            ['treatment_code' => 'REST64', 'procedure' => 'Temporary bridge placement', 'cost' => 300, 'category' => 'Restorative'],
            ['treatment_code' => 'REST65', 'procedure' => 'Occlusal adjustment after restoration', 'cost' => 100, 'category' => 'Restorative'],
            // Digital Restorative Procedures
            ['treatment_code' => 'REST66', 'procedure' => 'Digital intraoral scanning', 'cost' => 150, 'category' => 'Restorative'],
            ['treatment_code' => 'REST67', 'procedure' => 'CAD/CAM same-day crowns', 'cost' => 1500, 'category' => 'Restorative'],
            ['treatment_code' => 'REST68', 'procedure' => 'Chairside milled inlays/onlays', 'cost' => 1200, 'category' => 'Restorative'],
            ['treatment_code' => 'REST69', 'procedure' => 'Digital smile design for restorations', 'cost' => 500, 'category' => 'Restorative'],
        ];

        foreach ($treatments as $treatment) {
            Treatment::updateOrCreate(
                ['procedure' => $treatment['procedure']],
                $treatment
            );
        }
    }
}