<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\SoftTissueRecord;
use App\Models\OrthodonticRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
            'dentalRecords' => fn($q) => $q->latest(),
            'treatmentPlans' => fn($q) => $q->latest(),
            'images' => fn($q) => $q->latest(),
            'periodontalRecords' => fn($q) => $q->latest(),
            'softTissueRecords' => fn($q) => $q->latest(),
            'orthodonticRecords' => fn($q) => $q->latest(),
            'endodonticRecords' => fn($q) => $q->latest(),
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

        $patients = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'message' => 'Patients retrieved successfully.',
            'data' => $patients->items(),
            'meta' => [
                'current_page' => $patients->currentPage(),
                'last_page' => $patients->lastPage(),
                'per_page' => $patients->perPage(),
                'total' => $patients->total(),
            ]
        ]);
    }

    public function patientList(Request $request)
    {
        $query = Patient::query();

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

        $patients = $query->latest()->get();

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
            'dentalRecords' => fn($q) => $q->latest(),
            'treatmentPlans' => fn($q) => $q->latest(),
            'images' => fn($q) => $q->latest(),
            'periodontalRecords' => fn($q) => $q->latest(),
            'softTissueRecords' => fn($q) => $q->latest(),
            'orthodonticRecords' => fn($q) => $q->latest(),
            'endodonticRecords' => fn($q) => $q->latest(),
            'restorativeRecords' => fn($q) => $q->latest(),
            'paidInvoices' => fn($q) => $q->latest(),
            'unpaidInvoices' => fn($q) => $q->latest()
        ])->find($id);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json($patient);
    }

    /**
     * Get Patient History
     *
     * Retrieve the complete history for a patient, including medical history notes,
     * treatment plans, appointment history, invoices, lab orders, and dental records.
     *
     * @authenticated
     *
     * @urlParam id string required The patient's ID. Example: 1
     *
     * @response 200 {
     *   "id": "1",
     *   "first_name": "John",
     *   "last_name": "Doe",
     *   "history": "Patient has a history of grinding teeth.",
     *   "appointments": [
     *     {
     *       "id": "a1",
     *       "doctor_name": "Dr. Lisa Cuddy",
     *       "date": "2024-07-20",
     *       "time": "10:00 AM",
     *       "status": "Scheduled"
     *     }
     *   ],
     *   "treatment_plans": [
     *     {
     *       "procedure": "Filling for #16",
     *       "status": "Proposed",
     *       "cost": 250.00
     *     }
     *   ],
     *   "invoices": [
     *     {
     *       "id": "inv1",
     *       "amount": 250.00,
     *       "status": "Pending"
     *     }
     *   ]
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param string $id Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHistory($id)
    {
        $patient = Patient::with([
            'appointments' => fn($q) => $q->latest(),
            'treatmentPlans' => fn($q) => $q->latest(),
            'invoices' => fn($q) => $q->latest(),
            'labOrders' => fn($q) => $q->latest(),
            'dentalRecords' => fn($q) => $q->latest(),
            'images' => fn($q) => $q->latest(),
            'periodontalRecords' => fn($q) => $q->latest(),
            'softTissueRecords' => fn($q) => $q->latest(),
            'orthodonticRecords' => fn($q) => $q->latest(),
            'endodonticRecords' => fn($q) => $q->latest(),
            'branch',
        ])->find($id);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $history = [
            'id' => $patient->id,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'email' => $patient->email,
            'phone' => $patient->phone,
            'address' => $patient->address,
            'gender' => $patient->gender,
            'date_of_birth' => $patient->date_of_birth,
            'last_visit' => $patient->last_visit,
            'status' => $patient->status,
            'branch' => $patient->branch,
            'history' => $patient->history,
            'appointments' => $patient->appointments->map(function ($appointment) {
                return [
                    'id' => $appointment->id,
                    'doctor_name' => $appointment->doctor_name,
                    'treatment' => $appointment->treatment,
                    'status' => $appointment->status,
                    'date' => $appointment->date,
                    'time' => $appointment->time,
                    'branch_id' => $appointment->branch_id,
                ];
            }),
            'treatment_plans' => $patient->treatmentPlans,
            'invoices' => $patient->invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'amount' => $invoice->amount,
                    'paid_amount' => $invoice->paid_amount,
                    'status' => $invoice->status,
                    'issue_date' => $invoice->issue_date,
                    'due_date' => $invoice->due_date,
                ];
            }),
            'lab_orders' => $patient->labOrders,
            'dental_records' => $patient->dentalRecords,
            'images' => $patient->images,
            'periodontal_records' => $patient->periodontalRecords,
            'soft_tissue_records' => $patient->softTissueRecords,
            'orthodontic_records' => $patient->orthodonticRecords,
            'endodontic_records' => $patient->endodonticRecords,
            'restorative_records' => $patient->restorativeRecords,
        ];

        return response()->json($history);
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
        $patient->load('branch');

        if ($patient->email) {
            try {
                Mail::to($patient->email)->send(new \App\Mail\GenericMarkdownMail('emails.patient-welcome', [
                    'name' => $patient->first_name . ' ' . $patient->last_name,
                    'email' => $patient->email,
                    'branchName' => $patient->branch->name ?? 'Dental Clinic',
                ], 'Welcome to Our Practice! - Dental Management System'));
            } catch (\Exception $e) {
                logger()->error("Failed to send patient welcome email: " . $e->getMessage());
            }
        }

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
     *   "first_name": "John Smith Updated",
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
    public function updateNextVisit(Request $request, $id)
    {
        $patient = Patient::find($id);

        if (!$patient) {
            return response()->json(['message' => 'Patient not found'], 404);
        }

        $request->validate([
            'next_visit' => 'nullable|date',
        ]);

        $patient->next_visit = $request->next_visit;
        $patient->save();

        return response()->json($patient->load([
            'dentalRecords',
            'treatmentPlans',
            'imaging',
            'softTissueRecords',
            'orthodonticRecords',
            'endodonticRecords'
        ]));
    }

    public function update(Request $request, $id)
    {
        $patient = Patient::find($id);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'avatar' => 'nullable|string',
            'email' => 'sometimes|email|unique:patients,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'gender' => 'nullable|in:Male,Female,Other',
            'date_of_birth' => 'sometimes|date',
            'last_visit' => 'nullable|date',
            'status' => 'nullable|in:Active,Inactive',
            'branch_id' => 'sometimes|exists:branches,id',
            'history' => 'nullable|string',
            'occlusal_records' => 'nullable|json',
        ]);

        $patient->update($validated);

        return response()->json([
            'message' => 'Patient updated successfully.',
            'data' => $patient
        ], 200);
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

        \DB::transaction(function () use ($patient) {
            // Delete related invoices and their sub-records
            foreach ($patient->invoices as $invoice) {
                $invoice->treatments()->delete();
                $invoice->otherCharges()->delete();
                $invoice->transactions()->delete();
                $invoice->delete();
            }

            // Delete other related records
            $patient->dentalRecords()->delete();
            $patient->treatmentPlans()->delete();
            $patient->images()->delete();
            $patient->periodontalRecords()->delete();
            $patient->softTissueRecords()->delete();
            $patient->orthodonticRecords()->delete();
            $patient->endodonticRecords()->delete();
            $patient->appointments()->delete();
            $patient->labOrders()->delete();

            // Delete the patient
            $patient->delete();
        });

        return response()->json(['success' => true]);
    }

    /**
     * Get Patient Images
     *
     * Retrieve all images for a specific patient (X-rays, clinical photos, etc.).
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Images retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
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
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getImages($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json([
            'message' => 'Images retrieved successfully.',
            'data' => $patient->images()->latest()->get()
        ]);
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

        $patient->images()->create([
            ...$validated,
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('images'));
    }

    /**
     * Update Patient Image
     *
     * Update an existing patient image record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam imageId string required The image's ID. Example: 1
     * @bodyParam url string The updated URL of the image. Example: https://picsum.photos/800/700
     * @bodyParam description string The updated description. Example: Updated panoramic X-ray
     *
     * @response 200 {
     *   "message": "Image updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "url": "https://picsum.photos/800/700",
     *     "description": "Updated panoramic X-ray",
     *     "date": "2024-07-15"
     *   }
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Image not found"
     * }
     *
     * @param Request $request
     * @param string $patientId Patient ID
     * @param string $imageId Image ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateImage(Request $request, $patientId, $imageId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $image = $patient->images()->find($imageId);

        if (!$image) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $validated = $request->validate([
            'url' => 'sometimes|string',
            'description' => 'sometimes|string',
        ]);

        $image->update($validated);

        return response()->json([
            'message' => 'Image updated successfully.',
            'data' => $image
        ]);
    }

    /**
     * Delete Patient Image
     *
     * Delete a patient image record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam imageId string required The image's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Image deleted successfully."
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Image not found"
     * }
     *
     * @param string $patientId Patient ID
     * @param string $imageId Image ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteImage($patientId, $imageId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $image = $patient->images()->find($imageId);

        if (!$image) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $image->delete();

        return response()->json([
            'message' => 'Image deleted successfully.'
        ]);
    }

    /**
     * Get Dental Records (Odontogram)
     *
     * Retrieve all dental records for a specific patient's odontogram.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     *
     * @response 200 [
     *   {
     *     "tooth_id": 11,
     *     "condition": "Healthy",
     *     "notes": ""
     *   },
     *   {
     *     "tooth_id": 14,
     *     "condition": "Filling",
     *     "notes": "Composite filling placed on occlusal surface - 2023-06-15"
     *   },
     *   {
     *     "tooth_id": 16,
     *     "condition": "Caries",
     *     "notes": "Moderate cavity detected on mesial surface"
     *   }
     * ]
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDentalRecords($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        // Return only the array of dental records with tooth_id, condition, root_condition, and notes
        $records = $patient->dentalRecords()->latest()->get()->map(function ($record) {
            return [
                'tooth_id' => $record->tooth_id,
                'condition' => $record->condition,
                'root_condition' => $record->root_condition,
                'notes' => $record->notes ?? '',
            ];
        });

        return response()->json($records);
    }

    /**
     * Save Dental Record for Tooth
     *
     * Create or update a dental record for a specific tooth (odontogram update).
     * This is the primary endpoint for dental charting/odontogram updates.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 7
     * @bodyParam tooth_id integer required The tooth number (11-18, 21-28, 31-38, 41-48). Example: 13
     * @bodyParam condition string required The dental condition. Example: Filling
     * @bodyParam notes string Notes about the tooth condition. Example: Amalgam filling - procedure completed
     *
     * @response 201 {
     *   "message": "Dental record saved successfully.",
     *   "data": {
     *     "tooth_id": 13,
     *     "condition": "Filling",
     *     "notes": "Amalgam filling - procedure completed"
     *   }
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
            'root_condition' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        // Find existing record for this tooth
        $existingRecord = $patient->dentalRecords()
            ->where('tooth_id', $validated['tooth_id'])
            ->first();

        if ($existingRecord) {
            // Update existing record
            $existingRecord->update($validated);
            $record = $existingRecord;
            $message = 'Dental record updated successfully.';
            $statusCode = 200;
        } else {
            // Create new record
            $record = $patient->dentalRecords()->create($validated);
            $message = 'Dental record created successfully.';
            $statusCode = 201;
        }

        return response()->json([
            'message' => $message,
            'data' => [
                'tooth_id' => $record->tooth_id,
                'condition' => $record->condition,
                'root_condition' => $record->root_condition,
                'notes' => $record->notes ?? '',
            ]
        ], $statusCode);
    }

    /**
     * Update Dental Record
     *
     * Update an existing dental record for a specific tooth.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam recordId string required The dental record's ID. Example: 1
     * @bodyParam tooth_id integer The tooth number (1-32). Example: 16
     * @bodyParam condition string The dental condition. Example: Filled
     * @bodyParam notes string Notes about the tooth condition. Example: Composite filling completed
     *
     * @response 200 {
     *   "message": "Dental record updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "tooth_id": 16,
     *     "condition": "Filled",
     *     "notes": "Composite filling completed"
     *   }
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Dental record not found"
     * }
     *
     * @param Request $request
     * @param string $patientId Patient ID
     * @param string $recordId Record ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateDentalRecord(Request $request, $patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->dentalRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Dental record not found'], 404);
        }

        $validated = $request->validate([
            'tooth_id' => 'sometimes|integer',
            'condition' => 'sometimes|string',
            'notes' => 'nullable|string',
        ]);

        $record->update($validated);

        return response()->json([
            'message' => 'Dental record updated successfully.',
            'data' => $record
        ]);
    }

    /**
     * Delete Dental Record
     *
     * Delete a dental record for a specific tooth.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam recordId string required The dental record's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Dental record deleted successfully."
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Dental record not found"
     * }
     *
     * @param string $patientId Patient ID
     * @param string $recordId Record ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteDentalRecord($patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->dentalRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Dental record not found'], 404);
        }

        $record->delete();

        return response()->json([
            'message' => 'Dental record deleted successfully.'
        ]);
    }

    /**
     * Get Periodontal Records
     *
     * Retrieve all periodontal records for a specific patient.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Periodontal records retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
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
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPeriodontalRecords($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json([
            'message' => 'Periodontal records retrieved successfully.',
            'data' => $patient->periodontalRecords()->latest()->get()
        ]);
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
            'records.*.mgj' => 'nullable|integer',
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
     * Update Periodontal Record
     *
     * Update an existing periodontal record for a specific tooth.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam recordId string required The periodontal record's ID. Example: 1
     * @bodyParam tooth_id integer Tooth number. Example: 16
     * @bodyParam pockets array Pocket measurements
     * @bodyParam recession integer Gingival recession measurement. Example: 2
     * @bodyParam mobility string Tooth mobility. Example: 1
     * @bodyParam furcation string Furcation involvement. Example: I
     * @bodyParam conditions array Periodontal conditions
     *
     * @response 200 {
     *   "message": "Periodontal record updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "tooth_id": 16,
     *     "pockets": {
     *       "distobuccal": 3,
     *       "buccal": 2,
     *       "mesiobuccal": 3
     *     },
     *     "recession": 2,
     *     "mobility": "1",
     *     "furcation": "I",
     *     "conditions": {
     *       "bleeding": true,
     *       "calculus": false
     *     }
     *   }
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Periodontal record not found"
     * }
     *
     * @param Request $request
     * @param string $patientId Patient ID
     * @param string $recordId Record ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePeriodontalRecord(Request $request, $patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->periodontalRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Periodontal record not found'], 404);
        }

        $validated = $request->validate([
            'tooth_id' => 'sometimes|integer',
            'pockets' => 'sometimes|array',
            'recession' => 'sometimes|integer',
            'mgj' => 'nullable|integer',
            'mobility' => 'sometimes|string',
            'furcation' => 'nullable|string',
            'conditions' => 'sometimes|array',
        ]);

        $record->update($validated);

        return response()->json([
            'message' => 'Periodontal record updated successfully.',
            'data' => $record
        ]);
    }

    /**
     * Delete Periodontal Record
     *
     * Delete a periodontal record for a specific tooth.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam recordId string required The periodontal record's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Periodontal record deleted successfully."
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Periodontal record not found"
     * }
     *
     * @param string $patientId Patient ID
     * @param string $recordId Record ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deletePeriodontalRecord($patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->periodontalRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Periodontal record not found'], 404);
        }

        $record->delete();

        return response()->json([
            'message' => 'Periodontal record deleted successfully.'
        ]);
    }

    /**
     * Get Occlusal Records
     *
     * Retrieve the patient's occlusal analysis record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Occlusal records retrieved successfully.",
     *   "data": {
     *     "overbite": 2,
     *     "overjet": 3,
     *     "crossbite": "None",
     *     "openbite": "None",
     *     "malocclusion": "Class I"
     *   }
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOcclusalRecords($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json([
            'message' => 'Occlusal records retrieved successfully.',
            'data' => $patient->occlusal_records
        ]);
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
     * Update Occlusal Record
     *
     * Update the patient's occlusal analysis record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @bodyParam overbite integer Overbite measurement in mm. Example: 3
     * @bodyParam overjet integer Overjet measurement in mm. Example: 4
     * @bodyParam crossbite string Crossbite description. Example: Anterior
     * @bodyParam openbite string Openbite description. Example: None
     * @bodyParam malocclusion string Malocclusion classification. Example: Class II
     *
     * @response 200 {
     *   "message": "Occlusal record updated successfully.",
     *   "data": {
     *     "overbite": 3,
     *     "overjet": 4,
     *     "crossbite": "Anterior",
     *     "openbite": "None",
     *     "malocclusion": "Class II"
     *   }
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param Request $request
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOcclusalRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'overbite' => 'sometimes|integer',
            'overjet' => 'sometimes|integer',
            'crossbite' => 'sometimes|string',
            'openbite' => 'sometimes|string',
            'malocclusion' => 'sometimes|string',
        ]);

        $currentRecords = $patient->occlusal_records ?? [];
        $updatedRecords = array_merge($currentRecords, $validated);

        $patient->update(['occlusal_records' => $updatedRecords]);

        return response()->json([
            'message' => 'Occlusal record updated successfully.',
            'data' => $patient->occlusal_records
        ]);
    }

    /**
     * Delete Occlusal Record
     *
     * Clear the patient's occlusal analysis record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Occlusal record deleted successfully."
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteOcclusalRecord($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $patient->update(['occlusal_records' => null]);

        return response()->json([
            'message' => 'Occlusal record deleted successfully.'
        ]);
    }

    /**
     * Get Treatment Plans
     *
     * Retrieve all treatment plans for a specific patient.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Treatment plans retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "procedure": "Filling for #16",
     *       "tooth": 16,
     *       "status": "Proposed",
     *       "cost": 250.00,
     *       "date": "2024-08-01"
     *     }
     *   ]
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTreatments($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json([
            'message' => 'Treatments retrieved successfully.',
            'data' => $patient->treatmentPlans()->latest()->get()
        ]);
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
            'condition' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $treatmentData = \Illuminate\Support\Arr::only($validated, ['procedure', 'tooth', 'status', 'cost', 'date']);
        $patient->treatmentPlans()->create($treatmentData);

        if (isset($request->condition) && !empty($validated['tooth'])) {
            // Find existing record for this tooth
            $existingRecord = $patient->dentalRecords()
                ->where('tooth_id', $validated['tooth']) // Changed from 'tooth_id' to 'tooth'
                ->first();

            if ($existingRecord) {
                // Update existing record
                $existingRecord->update([
                    'condition' => $request->condition,
                    'tooth_id' => $validated['tooth'], // Changed from 'tooth_id' to 'tooth'
                    'notes' => $validated['notes'] ?? $existingRecord->notes
                ]);
                $record = $existingRecord;
            } else {
                // Create new record - you need to map 'tooth' to 'tooth_id'
                $recordData = [
                    'condition' => $request->condition,
                    'tooth_id' => $validated['tooth'],
                    'notes' => $validated['notes'] ?? null
                ];
                $record = $patient->dentalRecords()->create($recordData);
            }
        }

        return response()->json($patient->load('treatmentPlans'));
    }

    /**
     * Update Treatment Plan
     *
     * Update an existing treatment plan.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam treatmentId string required The treatment's ID. Example: 1
     * @bodyParam procedure string The treatment procedure. Example: Root canal for #16
     * @bodyParam tooth integer The tooth number. Example: 16
     * @bodyParam status string Treatment status. Example: Completed
     * @bodyParam cost number Cost of the treatment. Example: 500.00
     * @bodyParam date date Treatment date. Example: 2024-08-15
     *
     * @response 200 {
     *   "message": "Treatment plan updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "procedure": "Root canal for #16",
     *     "tooth": 16,
     *     "status": "Completed",
     *     "cost": 500.00,
     *     "date": "2024-08-15"
     *   }
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Treatment plan not found"
     * }
     *
     * @param Request $request
     * @param string $patientId Patient ID
     * @param string $treatmentId Treatment ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateTreatment(Request $request, $patientId, $treatmentId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $treatment = $patient->treatmentPlans()->find($treatmentId);

        if (!$treatment) {
            return response()->json(['error' => 'Treatment plan not found'], 404);
        }

        $validated = $request->validate([
            'procedure' => 'sometimes|string',
            'tooth' => 'nullable|integer',
            'status' => 'sometimes|in:Proposed,Completed',
            'cost' => 'sometimes|numeric',
            'date' => 'sometimes|date',
        ]);

        $treatment->update($validated);

        return response()->json([
            'message' => 'Treatment plan updated successfully.',
            'data' => $treatment
        ]);
    }

    /**
     * Update Treatment Plan Status
     *
     * Update only the status of an existing treatment plan.
     *
     * @authenticated
     *
     * @param Request $request
     * @param string $patientId Patient ID
     * @param string $treatmentId Treatment ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateTreatmentStatus(Request $request, $patientId, $treatmentId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $treatment = $patient->treatmentPlans()->find($treatmentId);

        if (!$treatment) {
            return response()->json(['error' => 'Treatment plan not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:Proposed,Completed',
        ]);

        $treatment->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Treatment plan status updated successfully.',
            'data' => $treatment
        ]);
    }

    /**
     * Delete Treatment Plan
     *
     * Delete a treatment plan.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam treatmentId string required The treatment's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Treatment plan deleted successfully."
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Treatment plan not found"
     * }
     *
     * @param string $patientId Patient ID
     * @param string $treatmentId Treatment ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteTreatment($patientId, $treatmentId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $treatment = $patient->treatmentPlans()->find($treatmentId);

        if (!$treatment) {
            return response()->json(['error' => 'Treatment plan not found'], 404);
        }

        $treatment->delete();

        return response()->json([
            'message' => 'Treatment plan deleted successfully.'
        ]);
    }

    /**
     * Get Soft Tissue Records
     *
     * Retrieve all soft tissue examination findings for a specific patient.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Soft tissue records retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "location": "Right buccal mucosa",
     *       "type": "Ulcer",
     *       "description": "Small aphthous ulcer, 3mm diameter",
     *       "date": "2024-07-15"
     *     }
     *   ]
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSoftTissueRecords($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json([
            'message' => 'Soft tissue records retrieved successfully.',
            'data' => $patient->softTissueRecords()->latest()->get()
        ]);
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
        try {
            
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'location' => 'required|string',
            'type' => 'required|string',
            'description' => 'required|string',
        ]);

        $patient->softTissueRecords()->create([
            ...$validated,
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('softTissueRecords'));
        }catch(Throwable $ex){
            return response()->json(['error' => $ex->getMessage()], 404);
        }
    }

    /**
     * Update Soft Tissue Record
     *
     * Update an existing soft tissue examination record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam recordId string required The soft tissue record's ID. Example: 1
     * @bodyParam location string Location of the finding. Example: Left buccal mucosa
     * @bodyParam type string Type of finding. Example: Lesion
     * @bodyParam description string Detailed description. Example: Healing aphthous ulcer, 2mm diameter
     *
     * @response 200 {
     *   "message": "Soft tissue record updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "location": "Left buccal mucosa",
     *     "type": "Lesion",
     *     "description": "Healing aphthous ulcer, 2mm diameter",
     *     "date": "2024-07-15"
     *   }
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Soft tissue record not found"
     * }
     *
     * @param Request $request
     * @param string $patientId Patient ID
     * @param string $recordId Record ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSoftTissueRecord(Request $request, $patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->softTissueRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Soft tissue record not found'], 404);
        }

        $validated = $request->validate([
            'location' => 'sometimes|string',
            'type' => 'sometimes|string',
            'description' => 'sometimes|string',
        ]);

        $record->update($validated);

        return response()->json([
            'message' => 'Soft tissue record updated successfully.',
            'data' => $record
        ]);
    }

    /**
     * Delete Soft Tissue Record
     *
     * Delete a soft tissue examination record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam recordId string required The soft tissue record's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Soft tissue record deleted successfully."
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Soft tissue record not found"
     * }
     *
     * @param string $patientId Patient ID
     * @param string $recordId Record ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteSoftTissueRecord($patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->softTissueRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Soft tissue record not found'], 404);
        }

        $record->delete();

        return response()->json([
            'message' => 'Soft tissue record deleted successfully.'
        ]);
    }

    /**
     * Get Orthodontic Records
     *
     * Retrieve all orthodontic treatment records for a specific patient.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Orthodontic records retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "appliance": "Braces",
     *       "wire_size": ".016 Nickel Titanium",
     *       "bracket_changes": ["14", "24"],
     *       "notes": "Good progress with alignment",
     *       "date": "2024-07-15"
     *     }
     *   ]
     * }
     * @response 404 {
     *   "error": "Patient not found"
     * }
     *
     * @param string $patientId Patient ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrthodonticRecords($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json([
            'message' => 'Orthodontic records retrieved successfully.',
            'data' => $patient->orthodonticRecords()->latest()->get()
        ]);
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
            'date' => 'nullable|date',
            'appliance' => 'required|string',
            'appliance_location' => 'nullable|string',
            'treatment_phase' => 'nullable|string',
            'wire_size' => 'nullable|string',
            'wire_size_upper' => 'nullable|string',
            'wire_size_lower' => 'nullable|string',
            'archwire_material' => 'nullable|string',
            'elastics' => 'nullable|string',
            'compliance' => 'nullable|string',
            'oral_hygiene' => 'nullable|string',
            'bracket_changes' => 'nullable|array',
            'broken_brackets' => 'nullable|array',
            'notes' => 'nullable|string',
            'next_visit_goals' => 'nullable|string',
        ]);

        $patient->orthodonticRecords()->create([
            ...$validated,
            'date' => $validated['date'] ?? now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('orthodonticRecords'));
    }

    /**
     * Update Orthodontic Record
     *
     * Update an existing orthodontic treatment record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam recordId string required The orthodontic record's ID. Example: 1
     * @bodyParam appliance string Orthodontic appliance used. Example: Clear Aligners
     * @bodyParam wire_size string Wire size used. Example: .018 Stainless Steel
     * @bodyParam bracket_changes array Brackets that were changed. Example: ["15", "25"]
     * @bodyParam notes string Treatment notes. Example: Excellent progress, teeth aligned
     *
     * @response 200 {
     *   "message": "Orthodontic record updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "appliance": "Clear Aligners",
     *     "wire_size": ".018 Stainless Steel",
     *     "bracket_changes": ["15", "25"],
     *     "notes": "Excellent progress, teeth aligned",
     *     "date": "2024-07-15"
     *   }
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Orthodontic record not found"
     * }
     *
     * @param Request $request
     * @param string $patientId Patient ID
     * @param string $recordId Record ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrthodonticRecord(Request $request, $patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->orthodonticRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Orthodontic record not found'], 404);
        }

        $validated = $request->validate([
            'date' => 'sometimes|date',
            'appliance' => 'sometimes|string',
            'appliance_location' => 'nullable|string',
            'treatment_phase' => 'nullable|string',
            'wire_size' => 'nullable|string',
            'wire_size_upper' => 'nullable|string',
            'wire_size_lower' => 'nullable|string',
            'archwire_material' => 'nullable|string',
            'elastics' => 'nullable|string',
            'compliance' => 'nullable|string',
            'oral_hygiene' => 'nullable|string',
            'bracket_changes' => 'nullable|array',
            'broken_brackets' => 'nullable|array',
            'notes' => 'nullable|string',
            'next_visit_goals' => 'nullable|string',
        ]);

        $record->update($validated);

        return response()->json([
            'message' => 'Orthodontic record updated successfully.',
            'data' => $record
        ]);
    }

    /**
     * Delete Orthodontic Record
     *
     * Delete an orthodontic treatment record.
     *
     * @authenticated
     *
     * @urlParam patientId string required The patient's ID. Example: 1
     * @urlParam recordId string required The orthodontic record's ID. Example: 1
     *
     * @response 200 {
     *   "message": "Orthodontic record deleted successfully."
     * }
     * @response 404 {
     *   "error": "Patient not found" or "Orthodontic record not found"
     * }
     *
     * @param string $patientId Patient ID
     * @param string $recordId Record ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteOrthodonticRecord($patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->orthodonticRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Orthodontic record not found'], 404);
        }

        $record->delete();

        return response()->json([
            'message' => 'Orthodontic record deleted successfully.'
        ]);
    }
    public function getToothHistory($patientId, $toothId)
    {
        $patient = Patient::find($patientId);
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        // Get dental records for this tooth
        $dentalRecords = $patient->dentalRecords()->where('tooth_id', $toothId)->get();

        // Get treatments for this tooth
        $treatments = $patient->treatmentPlans()->where('tooth', $toothId)->get();

        // Combine and sort by date
        $history = collect();

        foreach ($dentalRecords as $record) {
            $history->push([
                'id' => 'record_' . $record->id,
                'type' => 'Condition',
                'date' => $record->created_at->toIso8601String(),
                'details' => $record->condition,
                'notes' => $record->notes,
                'user' => 'System', // Placeholder if user relation not available
            ]);
        }

        foreach ($treatments as $treatment) {
            $history->push([
                'id' => 'treatment_' . $treatment->id,
                'type' => 'Treatment',
                'date' => $treatment->date . ' ' . ($treatment->time ?? '00:00:00'),
                'details' => $treatment->procedure,
                'status' => $treatment->status,
                'cost' => $treatment->cost,
                'notes' => $treatment->notes,
                'user' => 'System',
            ]);
        }

        $sortedHistory = $history->sortByDesc('date')->values();

        return response()->json([
            'toothId' => $toothId,
            'history' => $sortedHistory
        ]);
    }

    /**
     * Get all invoices of a patient.
     */
    public function getInvoices($id)
    {
        $patient = Patient::find($id);
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $invoices = $patient->invoices()->with(['branch', 'treatments', 'otherCharges', 'transactions'])->latest()->get();
        return response()->json($invoices);
    }

    /**
     * Endodontic Records Management
     */
    public function getEndodonticRecords($patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json([
            'message' => 'Endodontic records retrieved successfully.',
            'data' => $patient->endodonticRecords()->latest()->get()
        ]);
    }

    public function addEndodonticRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'tooth_id' => 'required|integer',
            'pulpal_diagnosis' => 'required|string',
            'periapical_diagnosis' => 'required|string',
            'canals' => 'required|array',
            'obturation_material' => 'nullable|string',
            'post_type' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $patient->endodonticRecords()->create([
            ...$validated,
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('endodonticRecords'));
    }

    public function updateEndodonticRecord(Request $request, $patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->endodonticRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Endodontic record not found'], 404);
        }

        $validated = $request->validate([
            'tooth_id' => 'sometimes|required|integer',
            'pulpal_diagnosis' => 'sometimes|required|string',
            'periapical_diagnosis' => 'sometimes|required|string',
            'canals' => 'sometimes|required|array',
            'obturation_material' => 'nullable|string',
            'post_type' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $record->update($validated);

        return response()->json([
            'message' => 'Endodontic record updated successfully.',
            'data' => $record
        ]);
    }

    public function deleteEndodonticRecord($patientId, $recordId)
    {
        $patient = Patient::find($patientId);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $record = $patient->endodonticRecords()->find($recordId);

        if (!$record) {
            return response()->json(['error' => 'Endodontic record not found'], 404);
        }

        $record->delete();

        return response()->json([
            'message' => 'Endodontic record deleted successfully.'
        ]);
    }
}
