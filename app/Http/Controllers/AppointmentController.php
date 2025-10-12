<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
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
            'id' => 'apt' . (Appointment::count() + 1),
            'status' => 'Scheduled',
            'token_number' => 'T' . Str::random(6),
        ]);

        return response()->json($appointment->load(['patient', 'doctor', 'branch']), 201);
    }

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

    public function destroy($id)
    {
        $appointment = Appointment::find($id);
        
        if (!$appointment) {
            return response()->json(['error' => 'Appointment not found'], 404);
        }

        $appointment->delete();

        return response()->json(['success' => true]);
    }

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