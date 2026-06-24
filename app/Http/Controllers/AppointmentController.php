<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $appointments = $query->get();

        return response()->json($appointments);
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
        ]);

        $appointment = Appointment::create([
            ...$validated,
            'status' => 'Scheduled',
            'token_number' => 'T' . Str::random(6),
        ]);

        return response()->json($appointment->load(['patient', 'doctor', 'branch']), 201);
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
            'status' => 'sometimes|in:Scheduled,Completed,Cancelled,Waiting',
            'date' => 'sometimes|date',
            'time' => 'sometimes|string',
            'branch_id' => 'sometimes|exists:branches,id',
        ]);

        $appointment->update($validated);

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
            'status' => 'required|in:Scheduled,Completed,Cancelled,Waiting',
        ]);

        $appointment->update($validated);

        return response()->json($appointment);
    }
}