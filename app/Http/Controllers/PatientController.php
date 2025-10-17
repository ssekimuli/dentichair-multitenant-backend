<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\DentalRecord;
use App\Models\TreatmentPlan;
use App\Models\PatientImage;
use App\Models\PeriodontalRecord;
use App\Models\SoftTissueRecord;
use App\Models\OrthodonticRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @group Patients
 * 
 * Endpoints for managing patient records, dental charting, and treatment planning.
 * Includes comprehensive dental record management with odontogram support.
 */
class PatientController extends Controller
{
    /**
     * Display a listing of patients.
     *
     * @group Patients
     * 
     * This endpoint returns a list of all patients with their related records.
     *
     * You can optionally filter by:
     * - **branch_id** — show only patients from a specific branch
     * - **status** — filter by Active/Inactive
     * - **gender** — filter by Male/Female/Other
     *
     * @queryParam branch_id integer Optional. Filter by branch ID.
     * @queryParam status string Optional. Filter by patient status (Active, Inactive).
     * @queryParam gender string Optional. Filter by gender (Male, Female, Other).
     *
     * @response 200 {
     *   "id": 1,
     *   "first_name": "John",
     *   "last_name": "Doe",
     *   "email": "john.doe@example.com",
     *   "phone": "256700000000",
     *   "address": "123 Kampala Road",
     *   "gender": "Male",
     *   "date_of_birth": "1985-05-20",
     *   "last_visit": "2024-05-10",
     *   "status": "Active",
     *   "branch_id": 1,
     *   "history": "Patient has a history of grinding teeth.",
     *   "occlusal_records": {
     *       "overbite": 2,
     *       "overjet": 1,
     *       "crossbite": "None"
     *   }
     * }
     */
    public function index(Request $request)
    {
        $query = Patient::with([
            'dentalRecords',
            'treatmentPlans',
            'images',
            'periodontalRecords',
            'softTissueRecords',
            'orthodonticRecords',
            'branch'
        ]);

        // Optional filters
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }

        $patients = $query->orderBy('first_name')->get();

        return response()->json([
            'message' => 'Patients retrieved successfully.',
            'data' => $patients
        ]);
    }


    /**
     * Get Patient by ID
     * 
     * Retrieve detailed information for a specific patient including all dental records,
     * treatment plans, images, and specialized records.
     * 
     * @authenticated
     * 
     * @urlParam id string required The patient's ID. Example: 1
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Doe",
     *   "email": "john.doe@example.com",
     *   "date_of_birth": "1985-05-20",
     *   "status": "Active",
     *   "branch_id": "b1",
     *   "dental_records": [
     *     {
     *       "tooth_id": 16,
     *       "condition": "Caries",
     *       "notes": "Distal caries, requires attention."
     *     }
     *   ],
     *   "treatment_plans": [
     *     {
     *       "procedure": "Filling for #16",
     *       "status": "Proposed",
     *       "cost": 250.00
     *     }
     *   ],
     *   "imaging": [
     *     {
     *       "url": "https://picsum.photos/seed/xray1/800/600",
     *       "description": "Panoramic X-ray"
     *     }
     *   ],
     *   "periodontal_records": [],
     *   "soft_tissue_records": [],
     *   "orthodontic_records": []
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     * 
     * @param string $id Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $patient = Patient::with([
            'dentalRecords',
            'treatmentPlans',
            'images',
            'periodontalRecords',
            'softTissueRecords',
            'orthodonticRecords'
        ])->find($id);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json($patient);
    }

    /**
     * Store a newly created patient.
     *
     * This endpoint allows you to create a new patient record in the system.
     *
     * @bodyParam first_name string required The patient's first name. Example: John
     * @bodyParam last_name string required The patient's last name. Example: Doe
     * @bodyParam avatar string The URL or path to the patient's avatar image. Example: https://example.com/avatar.jpg
     * @bodyParam email string required The patient's email address (must be unique). Example: johndoe@example.com
     * @bodyParam phone string The patient's phone number. Example: +256700123456
     * @bodyParam address string The patient's address. Example: Kampala, Uganda
     * @bodyParam gender string The patient's gender. Must be one of: Male, Female, Other. Example: Male
     * @bodyParam date_of_birth date required The patient's date of birth (Y-m-d format). Example: 1990-05-15
     * @bodyParam last_visit date The date of the patient's last visit (Y-m-d format). Example: 2025-01-10
     * @bodyParam status string The patient's current status. Must be one of: Active, Inactive. Example: Active
     * @bodyParam branch_id integer required The ID of the branch this patient belongs to. Example: 1
     * @bodyParam history string The patient's medical history or notes. Example: Has a history of gum disease.
     * @bodyParam occlusal_records json The patient's occlusal records in JSON format. Example: {"upper":"good","lower":"fair"}
     *
     * @response 201 {
     *   "message": "Patient created successfully.",
     *   "data": {
     *     "id": 1,
     *     "first_name": "John",
     *     "last_name": "Doe",
     *     "avatar": "https://example.com/avatar.jpg",
     *     "email": "johndoe@example.com",
     *     "phone": "+256700123456",
     *     "address": "Kampala, Uganda",
     *     "gender": "Male",
     *     "date_of_birth": "1990-05-15",
     *     "last_visit": "2025-01-10",
     *     "status": "Active",
     *     "branch_id": 1,
     *     "history": "Has a history of gum disease.",
     *     "occlusal_records": {"upper":"good","lower":"fair"},
     *     "created_at": "2025-10-17T12:00:00.000000Z",
     *     "updated_at": "2025-10-17T12:00:00.000000Z"
     *   }
     * }
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'avatar' => 'nullable|string',
            'email' => 'required|email|unique:patients,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'gender' => 'nullable|in:Male,Female,Other',
            'date_of_birth' => 'required|date',
            'last_visit' => 'nullable|date',
            'status' => 'nullable|in:Active,Inactive',
            'branch_id' => 'required|exists:branches,id',
            'history' => 'nullable|string',
            'occlusal_records' => 'nullable|json',
        ]);

        $patient = Patient::create($validated);

        return response()->json([
            'message' => 'Patient created successfully.',
            'data' => $patient
        ], 201);
    }



    /**
     * Update Patient
     * 
     * Update an existing patient's information.
     * 
     * @authenticated
     * 
     * @urlParam id string required The patient's ID. Example: 1
     * @bodyParam name string Patient's full name. Example: John Smith Updated
     * @bodyParam email string Patient's email address. Example: john.updated@example.com
     * @bodyParam date_of_birth date Patient's date of birth. Example: 1985-03-15
     * @bodyParam status string Patient status. Example: Inactive
     * @bodyParam branch_id string Branch ID. Example: b2
     * @bodyParam history string Patient's medical/dental history. Example: Updated medical history
     * @bodyParam occlusal_records array Occlusal records data
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Smith Updated",
     *   "email": "john.updated@example.com",
     *   "status": "Active",
     *   "branch_id": "b1"
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     * 
     * @param Request $request
     * @param string $id Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $patient = Patient::find($id);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'avatar' => 'nullable|string',
            'email' => 'sometimes|email',
            'date_of_birth' => 'sometimes|date',
            'last_visit' => 'nullable|date',
            'status' => 'sometimes|in:Active,Inactive',
            'branch_id' => 'sometimes|exists:branches,id',
            'history' => 'nullable|string',
            'occlusal_records' => 'nullable|array',
        ]);

        $patient->update($validated);

        return response()->json($patient->load(['dentalRecords', 'treatmentPlans', 'images']));
    }

    /**
     * Delete Patient
     * 
     * Permanently delete a patient record and all associated data.
     * 
     * @authenticated
     * 
     * @urlParam id string required The patient's ID. Example: 1
     * 
     * @response 200 {
     *   "success": true
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     * 
     * @param string $id Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $patient = Patient::find($id);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $patient->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Add Patient Image
     * 
     * Add a new image to patient's records (X-rays, clinical photos, etc.).
     * 
     * @authenticated
     * 
     * @urlParam patientId string required The patient's ID. Example: 1
     * @bodyParam url string required URL of the image. Example: https://picsum.photos/800/600
     * @bodyParam description string required Description of the image. Example: Panoramic X-ray showing full dentition
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Doe",
     *   "imaging": [
     *     {
     *       "url": "https://picsum.photos/800/600",
     *       "description": "Panoramic X-ray showing full dentition",
     *       "date": "2024-07-15"
     *     }
     *   ]
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     * 
     * @param Request $request
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function addImage(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'url' => 'required|string',
            'description' => 'required|string',
        ]);

        $image = $patient->images()->create([
            ...$validated,
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('images'));
    }

    /**
     * Update Dental Record for Tooth
     * 
     * Update or create a dental record for a specific tooth (odontogram update).
     * This is the primary endpoint for dental charting/odontogram updates.
     * 
     * @authenticated
     * 
     * @urlParam patientId string required The patient's ID. Example: 1
     * @bodyParam tooth_id integer required The tooth number (1-32). Example: 16
     * @bodyParam condition string required The dental condition. Example: Caries
     * @bodyParam notes string Notes about the tooth condition. Example: Distal caries, requires attention.
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Doe",
     *   "dental_records": [
     *     {
     *       "tooth_id": 16,
     *       "condition": "Caries",
     *       "notes": "Distal caries, requires attention."
     *     }
     *   ]
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     * 
     * @param Request $request
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveDentalRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'tooth_id' => 'required|integer',
            'condition' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $existingRecord = $patient->dentalRecords()
            ->where('tooth_id', $validated['tooth_id'])
            ->first();

        if ($existingRecord) {
            $existingRecord->update($validated);
        } else {
            $patient->dentalRecords()->create($validated);
        }

        return response()->json($patient->load('dentalRecords'));
    }

    /**
     * Save Periodontal Records
     * 
     * Save or update periodontal records for multiple teeth.
     * Replaces all existing periodontal records for the patient.
     * 
     * @authenticated
     * 
     * @urlParam patientId string required The patient's ID. Example: 1
     * @bodyParam records array required Array of periodontal records
     * @bodyParam records.*.tooth_id integer required Tooth number. Example: 16
     * @bodyParam records.*.pockets array required Pocket measurements
     * @bodyParam records.*.recession integer required Gingival recession measurement. Example: 2
     * @bodyParam records.*.mobility string required Tooth mobility. Example: 1
     * @bodyParam records.*.furcation string Furcation involvement. Example: I
     * @bodyParam records.*.conditions array required Periodontal conditions
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Doe",
     *   "periodontal_records": [
     *     {
     *       "tooth_id": 16,
     *       "pockets": {
     *         "distobuccal": 3,
     *         "buccal": 2,
     *         "mesiobuccal": 3
     *       },
     *       "recession": 2,
     *       "mobility": "1",
     *       "furcation": "I",
     *       "conditions": {
     *         "bleeding": true,
     *         "calculus": false
     *       }
     *     }
     *   ]
     * }
     * 
     * @param Request $request
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function savePeriodontalRecords(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $records = $request->validate([
            'records' => 'required|array',
            'records.*.tooth_id' => 'required|integer',
            'records.*.pockets' => 'required|array',
            'records.*.recession' => 'required|integer',
            'records.*.mobility' => 'required|string',
            'records.*.furcation' => 'nullable|string',
            'records.*.conditions' => 'required|array',
        ]);

        $patient->periodontalRecords()->delete();

        foreach ($records['records'] as $record) {
            $patient->periodontalRecords()->create($record);
        }

        return response()->json($patient->load('periodontalRecords'));
    }

    /**
     * Save Occlusal Record
     * 
     * Update the patient's occlusal analysis record.
     * 
     * @authenticated
     * 
     * @urlParam patientId string required The patient's ID. Example: 1
     * @bodyParam overbite integer required Overbite measurement in mm. Example: 2
     * @bodyParam overjet integer required Overjet measurement in mm. Example: 3
     * @bodyParam crossbite string required Crossbite description. Example: None
     * @bodyParam openbite string required Openbite description. Example: None
     * @bodyParam malocclusion string required Malocclusion classification. Example: Class I
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Doe",
     *   "occlusal_records": {
     *     "overbite": 2,
     *     "overjet": 3,
     *     "crossbite": "None",
     *     "openbite": "None",
     *     "malocclusion": "Class I"
     *   }
     * }
     * 
     * @param Request $request
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveOcclusalRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'overbite' => 'required|integer',
            'overjet' => 'required|integer',
            'crossbite' => 'required|string',
            'openbite' => 'required|string',
            'malocclusion' => 'required|string',
        ]);

        $patient->update(['occlusal_records' => $validated]);

        return response()->json($patient);
    }

    /**
     * Add Treatment Plan
     * 
     * Create a new treatment plan for a patient.
     * 
     * @authenticated
     * 
     * @urlParam patientId string required The patient's ID. Example: 1
     * @bodyParam procedure string required The treatment procedure. Example: Filling for #16
     * @bodyParam tooth integer The tooth number if procedure is tooth-specific. Example: 16
     * @bodyParam status string required Treatment status. Example: Proposed
     * @bodyParam cost number required Cost of the treatment. Example: 250.00
     * @bodyParam date date required Treatment date. Example: 2024-08-01
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Doe",
     *   "treatment_plans": [
     *     {
     *       "procedure": "Filling for #16",
     *       "tooth": 16,
     *       "status": "Proposed",
     *       "cost": 250.00,
     *       "date": "2024-08-01"
     *     }
     *   ]
     * }
     * 
     * @param Request $request
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function addTreatment(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'procedure' => 'required|string',
            'tooth' => 'nullable|integer',
            'status' => 'required|in:Proposed,Completed',
            'cost' => 'required|numeric',
            'date' => 'required|date',
        ]);

        $treatment = $patient->treatmentPlans()->create([
            ...$validated,
            'id' => 'tp' . (TreatmentPlan::count() + 1),
        ]);

        return response()->json($patient->load('treatmentPlans'));
    }

    /**
     * Add Soft Tissue Record
     * 
     * Record soft tissue examination findings.
     * 
     * @authenticated
     * 
     * @urlParam patientId string required The patient's ID. Example: 1
     * @bodyParam location string required Location of the finding. Example: Right buccal mucosa
     * @bodyParam type string required Type of finding. Example: Ulcer
     * @bodyParam description string required Detailed description. Example: Small aphthous ulcer, 3mm diameter
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Doe",
     *   "soft_tissue_records": [
     *     {
     *       "location": "Right buccal mucosa",
     *       "type": "Ulcer",
     *       "description": "Small aphthous ulcer, 3mm diameter",
     *       "date": "2024-07-15"
     *     }
     *   ]
     * }
     * 
     * @param Request $request
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function addSoftTissueRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'location' => 'required|string',
            'type' => 'required|string',
            'description' => 'required|string',
        ]);

        $record = $patient->softTissueRecords()->create([
            ...$validated,
            'id' => 'st' . (SoftTissueRecord::count() + 1),
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('softTissueRecords'));
    }

    /**
     * Add Orthodontic Record
     * 
     * Record orthodontic treatment progress or consultation.
     * 
     * @authenticated
     * 
     * @urlParam patientId string required The patient's ID. Example: 1
     * @bodyParam appliance string required Orthodontic appliance used. Example: Braces
     * @bodyParam wire_size string Wire size used. Example: .016 Nickel Titanium
     * @bodyParam bracket_changes array Brackets that were changed. Example: ["14", "24"]
     * @bodyParam notes string Treatment notes. Example: Good progress with alignment
     * 
     * @response 200 {
     *   "id": "1",
     *   "name": "John Doe",
     *   "orthodontic_records": [
     *     {
     *       "appliance": "Braces",
     *       "wire_size": ".016 Nickel Titanium",
     *       "bracket_changes": ["14", "24"],
     *       "notes": "Good progress with alignment",
     *       "date": "2024-07-15"
     *     }
     *   ]
     * }
     * 
     * @param Request $request
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function addOrthodonticRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'appliance' => 'required|string',
            'wire_size' => 'nullable|string',
            'bracket_changes' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $record = $patient->orthodonticRecords()->create([
            ...$validated,
            'id' => 'or' . (OrthodonticRecord::count() + 1),
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('orthodonticRecords'));
    }
}
