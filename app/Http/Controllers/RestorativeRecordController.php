<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\RestorativeRecord;
use Illuminate\Http\Request;

class RestorativeRecordController extends Controller
{
    public function store(Request $request, Patient $patient)
    {
        $validated = $request->validate([
            'tooth_id' => 'required|integer',
            'surface' => 'required|string',
            'material' => 'required|string',
            'notes' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'procedure_cost' => 'nullable|numeric|min:0',
            'material_cost' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
        ]);

        $record = $patient->restorativeRecords()->create(
            \Illuminate\Support\Arr::except($validated, ['cost', 'procedure_cost', 'material_cost', 'other_charges'])
        );

        $procedureCost = $validated['procedure_cost'] ?? 0;
        $materialCost = $validated['material_cost'] ?? 0;
        $otherCharges = $validated['other_charges'] ?? 0;
        $totalCost = $procedureCost + $materialCost + $otherCharges;

        if ($totalCost == 0 && !empty($validated['cost']) && $validated['cost'] > 0) {
            $totalCost = $validated['cost'];
            $procedureCost = $totalCost;
        }

        if ($totalCost > 0) {
            $invoice = \App\Models\Invoice::where('patient_id', $patient->id)
                ->whereDate('issue_date', today())
                ->where('branch_id', $patient->branch_id)
                ->whereIn('status', ['Pending', 'Overdue'])
                ->first();
                
            if (!$invoice) {
                $invoice = \App\Models\Invoice::create([
                    'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                    'patient_id' => $patient->id,
                    'amount' => 0,
                    'paid_amount' => 0,
                    'status' => 'Pending',
                    'issue_date' => today(),
                    'due_date' => today()->addDays(30),
                    'branch_id' => $patient->branch_id,
                    'category' => 'procedure-income',
                ]);
            }

            $invoice->increment('amount', $totalCost);

            if ($procedureCost > 0) {
                $invoice->treatments()->create([
                    'procedure' => 'Restoration Tooth #' . $record->tooth_id . ' (' . $record->surface . ' - ' . $record->material . ') - Procedure',
                    'cost' => $procedureCost
                ]);
            }
            if ($materialCost > 0) {
                $invoice->treatments()->create([
                    'procedure' => 'Restoration Tooth #' . $record->tooth_id . ' - Material',
                    'cost' => $materialCost
                ]);
            }
            if ($otherCharges > 0) {
                $invoice->treatments()->create([
                    'procedure' => 'Restoration Tooth #' . $record->tooth_id . ' - Other Charges',
                    'cost' => $otherCharges
                ]);
            }
        }

        return response()->json($record, 201);
    }

    public function update(Request $request, Patient $patient, RestorativeRecord $restorativeRecord)
    {
        if ($restorativeRecord->patient_id !== $patient->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'tooth_id' => 'required|integer',
            'surface' => 'required|string',
            'material' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $restorativeRecord->update($validated);

        return response()->json($restorativeRecord);
    }

    public function destroy(Patient $patient, RestorativeRecord $restorativeRecord)
    {
        if ($restorativeRecord->patient_id !== $patient->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $restorativeRecord->delete();

        return response()->json(null, 204);
    }
}
