<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\TreatmentPlan;
use Illuminate\Http\Request;

class TreatmentController extends Controller
{
    public function store(Request $request, Patient $patient)
    {
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
        $treatmentPlan = $patient->treatmentPlans()->create($treatmentData);

        if (isset($request->condition) && !empty($validated['tooth'])) {
            $existingRecord = $patient->dentalRecords()
                ->where('tooth_id', $validated['tooth'])
                ->first();

            if ($existingRecord) {
                $existingRecord->update([
                    'condition' => $request->condition,
                    'tooth_id' => $validated['tooth'],
                    'notes' => $validated['notes'] ?? $existingRecord->notes
                ]);
            } else {
                $recordData = [
                    'condition' => $request->condition,
                    'tooth_id' => $validated['tooth'],
                    'notes' => $validated['notes'] ?? null
                ];
                $patient->dentalRecords()->create($recordData);
            }
        }

        return response()->json($treatmentPlan, 201);
    }
}
