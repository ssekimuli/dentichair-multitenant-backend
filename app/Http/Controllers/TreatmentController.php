<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\TreatmentPlan;
use Illuminate\Http\Request;

class TreatmentController extends Controller
{
    /**
     * Store a new treatment for the given patient.
     */
    public function store(Request $request, Patient $patient)
    {
        $validated = $request->validate([
            'procedure' => 'required|string',
            'cost' => 'required|numeric',
            'treatment_id' => 'nullable|integer|exists:treatments,id',
            'appointment_id' => 'nullable|integer|exists:appointments,id',
            'doctor_id' => 'nullable|integer',
            'status' => 'nullable|string',
            'notes' => 'nullable|string',
            'date' => 'nullable|date',
            'time' => 'nullable|date_format:H:i',
            'token_number' => 'nullable|string',
            'tooth' => 'nullable|string',
        ]);

        $validated['branch_id'] = $patient->branch_id;

        $treatmentPlan = $patient->treatmentPlans()->create($validated);

        return response()->json([
            'message' => 'Treatment created successfully',
            'data' => $treatmentPlan
        ], 201);
    }
}
