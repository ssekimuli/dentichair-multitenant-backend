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
            'tooth' => 'nullable',
            'teeth' => 'nullable|array',
            'teeth.*' => 'integer',
        ]);

        $validated['branch_id'] = $patient->branch_id;
        $validated['status'] = in_array($validated['status'] ?? '', ['Proposed', 'Completed'])
            ? $validated['status']
            : 'Proposed';

        $teeth = [];
        if (!empty($validated['teeth']) && is_array($validated['teeth'])) {
            $teeth = $validated['teeth'];
        } elseif (isset($validated['tooth']) && $validated['tooth'] !== null && $validated['tooth'] !== '') {
            $teeth = [(int)$validated['tooth']];
        } else {
            $teeth = [null];
        }

        $created = [];
        foreach ($teeth as $t) {
            $data = $validated;
            unset($data['teeth']);
            $data['tooth'] = $t;
            $created[] = $patient->treatmentPlans()->create($data);
        }

        return response()->json([
            'message' => 'Treatment created successfully',
            'data' => count($created) === 1 ? $created[0] : $created
        ], 201);
    }
}
