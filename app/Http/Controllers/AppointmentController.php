<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\TreatmentPlan;
use App\Models\Treatment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

/**
 * @group Appointments
 * 
 * Endpoints for managing patient appointments, scheduling, and appointment status.
 * Includes queue management and appointment tracking features.
 */
class AppointmentController extends Controller
{
    /**
     * Get All Appointments
     * 
     * Retrieve a list of appointments with optional filtering by branch, date, or status.
     * Returns complete appointment details including patient, doctor, and branch information.
     * 
     * @authenticated
     * 
     * @queryParam branch_id string Filter appointments by branch ID. Example: b1
     * @queryParam date string Filter appointments by specific date (YYYY-MM-DD). Example: 2024-07-20
     * @queryParam status string Filter appointments by status. Example: Scheduled
     * 
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "a1",
     *       "patient_name": "John Doe",
     *       "patient_id": "1",
     *       "doctor_name": "Dr. Lisa Cuddy",
     *       "doctor_id": "emp1",
     *       "treatment": "Check-up",
     *       "status": "Scheduled",
     *       "date": "2024-07-20",
     *       "time": "10:00 AM",
     *       "branch_id": "b1",
     *       "token_number": "JDLC1",
     *       "patient": {
     *         "id": "1",
     *         "name": "John Doe",
     *         "email": "john.doe@example.com"
     *       },
     *       "doctor": {
     *         "id": "emp1",
     *         "name": "Dr. Lisa Cuddy",
     *         "role": "Dentist"
     *       },
     *       "branch": {
     *         "id": "b1",
     *         "name": "Downtown Clinic"
     *       }
     *     }
     *   ]
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Appointment::with(['patient', 'doctor', 'branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $appointments = $query->paginate($request->input('per_page', 10));

        return response()->json([
            'data' => $appointments->items(),
            'meta' => [
                'current_page' => $appointments->currentPage(),
                'last_page' => $appointments->lastPage(),
                'per_page' => $appointments->perPage(),
                'total' => $appointments->total(),
            ]
        ]);
    }

    /**
     * Create Appointment
     * 
     * Schedule a new appointment for a patient. Automatically generates a unique token number
     * and sets initial status to "Scheduled".
     * 
     * @authenticated
     * 
     * @bodyParam patient_name string required Patient's full name. Example: John Doe
     * @bodyParam patient_id string required Patient's ID. Example: 1
     * @bodyParam doctor_name string required Doctor's full name. Example: Dr. Lisa Cuddy
     * @bodyParam doctor_id string required Doctor's employee ID. Example: emp1
     * @bodyParam treatment string required Treatment or procedure description. Example: Dental Checkup
     * @bodyParam date date required Appointment date (YYYY-MM-DD). Example: 2024-07-20
     * @bodyParam time string required Appointment time. Example: 10:00 AM
     * @bodyParam branch_id string required Branch ID. Example: b1
     * 
     * @response 201 {
     *   "id": "apt7",
     *   "patient_name": "John Doe",
     *   "patient_id": "1",
     *   "doctor_name": "Dr. Lisa Cuddy",
     *   "doctor_id": "emp1",
     *   "treatment": "Dental Checkup",
     *   "status": "Scheduled",
     *   "date": "2024-07-20",
     *   "time": "10:00 AM",
     *   "branch_id": "b1",
     *   "token_number": "TABC123",
     *   "patient": {
     *     "id": "1",
     *     "name": "John Doe"
     *   },
     *   "doctor": {
     *     "id": "emp1",
     *     "name": "Dr. Lisa Cuddy"
     *   },
     *   "branch": {
     *     "id": "b1",
     *     "name": "Downtown Clinic"
     *   }
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "patient_name": ["The patient name field is required."],
     *     "patient_id": ["The patient id field is required."],
     *     "doctor_name": ["The doctor name field is required."],
     *     "treatment": ["The treatment field is required."],
     *     "date": ["The date field is required."],
     *     "time": ["The time field is required."],
     *     "branch_id": ["The branch id field is required."]
     *   }
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_name' => 'required|string',
            'patient_id' => 'required|exists:patients,id',
            'doctor_name' => 'required|string',
            'doctor_id' => 'required|exists:employees,id',
            'treatment' => 'required|string',
            'date' => 'required|date',
            'time' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
            'token_number' => 'nullable|string',
        ]);

        $appointment = Appointment::create([
            ...$validated,
            'status' => 'Scheduled',
            'token_number' => empty($validated['token_number']) ? 'T' . Str::random(6) : $validated['token_number'],
        ]);

        $appointment->load(['patient', 'doctor', 'branch']);

        // Send Email notification to patient if they have an email
        if ($appointment->patient && $appointment->patient->email) {
            try {
                Mail::send('emails.appointment-confirmation', [
                    'patientName' => $appointment->patient->first_name . ' ' . $appointment->patient->last_name,
                    'tokenNumber' => $appointment->token_number,
                    'doctorName' => $appointment->doctor_name,
                    'treatment' => $appointment->treatment,
                    'date' => $appointment->date->toDateString(),
                    'time' => $appointment->time,
                    'branchName' => $appointment->branch->name ?? 'Dental Clinic',
                ], function ($message) use ($appointment) {
                    $message->to($appointment->patient->email)
                            ->subject('Appointment Scheduled - Dental Management System');
                });
            } catch (\Exception $e) {
                logger()->error("Failed to send appointment confirmation email: " . $e->getMessage());
            }
        }

        return response()->json($appointment, 201);
    }

    /**
     * Update Appointment
     * 
     * Update an existing appointment's details. Can update any field except the ID.
     * 
     * @authenticated
     * 
     * @urlParam id string required The appointment ID. Example: a1
     * @bodyParam patient_name string Patient's full name. Example: John Doe Updated
     * @bodyParam patient_id string Patient's ID. Example: 1
     * @bodyParam doctor_name string Doctor's full name. Example: Dr. Mike Chen
     * @bodyParam doctor_id string Doctor's employee ID. Example: emp2
     * @bodyParam treatment string Treatment or procedure description. Example: Teeth Cleaning
     * @bodyParam status string Appointment status. Example: Completed
     * @bodyParam date date Appointment date (YYYY-MM-DD). Example: 2024-07-21
     * @bodyParam time string Appointment time. Example: 11:00 AM
     * @bodyParam branch_id string Branch ID. Example: b1
     * 
     * @response 200 {
     *   "id": "a1",
     *   "patient_name": "John Doe Updated",
     *   "patient_id": "1",
     *   "doctor_name": "Dr. Mike Chen",
     *   "doctor_id": "emp2",
     *   "treatment": "Teeth Cleaning",
     *   "status": "Completed",
     *   "date": "2024-07-21",
     *   "time": "11:00 AM",
     *   "branch_id": "b1",
     *   "token_number": "JDLC1",
     *   "patient": {
     *     "id": "1",
     *     "name": "John Doe"
     *   },
     *   "doctor": {
     *     "id": "emp2",
     *     "name": "Dr. Mike Chen"
     *   },
     *   "branch": {
     *     "id": "b1",
     *     "name": "Downtown Clinic"
     *   }
     * }
     * @response 404 {
     *   "error": "Appointment not found"
     * }
     * 
     * @param Request $request
     * @param string $id Appointment ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $appointment = Appointment::find($id);
        
        if (!$appointment) {
            return response()->json(['error' => 'Appointment not found'], 404);
        }

        $validated = $request->validate([
            'patient_name' => 'sometimes|string',
            'patient_id' => 'sometimes|exists:patients,id',
            'doctor_name' => 'sometimes|string',
            'doctor_id' => 'sometimes|exists:employees,id',
            'treatment' => 'sometimes|string',
            'status' => 'sometimes|in:Scheduled,Completed,Cancelled,Waiting,In Session,On Hold',
            'date' => 'sometimes|date',
            'time' => 'sometimes|string',
            'branch_id' => 'sometimes|exists:branches,id',
            'token_number' => 'sometimes|nullable|string',
        ]);

        $oldStatus = $appointment->status;
        $appointment->update($validated);

        if (isset($validated['status']) && $oldStatus !== $appointment->status) {
            $appointment->load(['patient', 'doctor']);
            if ($appointment->status === 'Completed') {
                $this->generateInvoiceForCompletedAppointment($appointment);
            }
            if ($appointment->patient && $appointment->patient->email) {
                try {
                    Mail::send('emails.appointment-status', [
                        'patientName' => $appointment->patient->first_name . ' ' . $appointment->patient->last_name,
                        'status' => $appointment->status,
                        'tokenNumber' => $appointment->token_number,
                        'doctorName' => $appointment->doctor_name,
                        'treatment' => $appointment->treatment,
                        'date' => $appointment->date->toDateString(),
                        'time' => $appointment->time,
                    ], function ($message) use ($appointment) {
                        $message->to($appointment->patient->email)
                                ->subject('Appointment Status Update - Dental Management System');
                    });
                } catch (\Exception $e) {
                    logger()->error("Failed to send appointment status update email: " . $e->getMessage());
                }
            }
        }

        return response()->json($appointment->load(['patient', 'doctor', 'branch']));
    }

    /**
     * Delete Appointment
     * 
     * Permanently cancel and remove an appointment from the system.
     * 
     * @authenticated
     * 
     * @urlParam id string required The appointment ID. Example: a1
     * 
     * @response 200 {
     *   "success": true
     * }
     * @response 404 {
     *   "error": "Appointment not found"
     * }
     * 
     * @param string $id Appointment ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $appointment = Appointment::find($id);
        
        if (!$appointment) {
            return response()->json(['error' => 'Appointment not found'], 404);
        }

        $appointment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Update Appointment Status
     * 
     * Update only the status of an appointment. Useful for queue management
     * (e.g., moving from "Scheduled" to "Waiting" to "Completed").
     * 
     * @authenticated
     * 
     * @urlParam id string required The appointment ID. Example: a1
     * @bodyParam status string required New appointment status. Example: Completed
     * 
     * @response 200 {
     *   "id": "a1",
     *   "patient_name": "John Doe",
     *   "patient_id": "1",
     *   "doctor_name": "Dr. Lisa Cuddy",
     *   "doctor_id": "emp1",
     *   "treatment": "Check-up",
     *   "status": "Completed",
     *   "date": "2024-07-20",
     *   "time": "10:00 AM",
     *   "branch_id": "b1",
     *   "token_number": "JDLC1"
     * }
     * @response 404 {
     *   "error": "Appointment not found"
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "status": [
     *       "The status field is required.",
     *       "The selected status is invalid."
     *     ]
     *   }
     * }
     * 
     * @param Request $request
     * @param string $id Appointment ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $appointment = Appointment::find($id);
        
        if (!$appointment) {
            return response()->json(['error' => 'Appointment not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:Scheduled,Completed,Cancelled,Waiting,In Session,On Hold',
        ]);

        $oldStatus = $appointment->status;
        $appointment->update($validated);

        if ($oldStatus !== $appointment->status) {
            $appointment->load(['patient', 'doctor']);
            if ($appointment->status === 'Completed') {
                $this->generateInvoiceForCompletedAppointment($appointment);
            }
            if ($appointment->patient && $appointment->patient->email) {
                try {
                    Mail::send('emails.appointment-status', [
                        'patientName' => $appointment->patient->first_name . ' ' . $appointment->patient->last_name,
                        'status' => $appointment->status,
                        'tokenNumber' => $appointment->token_number,
                        'doctorName' => $appointment->doctor_name,
                        'treatment' => $appointment->treatment,
                        'date' => $appointment->date->toDateString(),
                        'time' => $appointment->time,
                    ], function ($message) use ($appointment) {
                        $message->to($appointment->patient->email)
                                ->subject('Appointment Status Update - Dental Management System');
                    });
                } catch (\Exception $e) {
                    logger()->error("Failed to send appointment status update email: " . $e->getMessage());
                }
            }
        }

        return response()->json($appointment);
    }

    /**
     * Generate a completed treatment plan and invoice for a completed appointment.
     *
     * @param \App\Models\Appointment $appointment
     * @return void
     */
    private function generateInvoiceForCompletedAppointment($appointment)
    {
        // 1. Check if a completed TreatmentPlan already exists for this patient, procedure, and date
        $exists = TreatmentPlan::where('patient_id', $appointment->patient_id)
            ->where('procedure', $appointment->treatment)
            ->whereDate('date', $appointment->date)
            ->where('status', 'Completed')
            ->exists();

        if ($exists) {
            return;
        }

        // 2. Find the cost of the treatment (procedure)
        $treatmentCost = 0.00;
        if ($appointment->treatment) {
            $treatment = Treatment::where('procedure', $appointment->treatment)->first();
            if ($treatment) {
                $treatmentCost = $treatment->cost;
            }
        }

        // 3. Create the completed treatment plan
        TreatmentPlan::create([
            'patient_id' => $appointment->patient_id,
            'procedure' => $appointment->treatment,
            'cost' => $treatmentCost,
            'status' => 'Completed',
            'date' => $appointment->date ? $appointment->date->format('Y-m-d') : now()->format('Y-m-d'),
        ]);
    }
}