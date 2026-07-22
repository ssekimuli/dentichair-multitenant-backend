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
        ]);

        $record = $patient->restorativeRecords()->create(
            \Illuminate\Support\Arr::except($validated, ['cost'])
        );

        if (!empty($validated['cost']) && $validated['cost'] > 0) {
            $cost = $validated['cost'];
            $invoice = \App\Models\Invoice::where('patient_id', $patient->id)
                ->whereDate('issue_date', today())
                ->where('branch_id', $patient->branch_id)
                ->whereIn('status', ['Pending', 'Overdue'])
                ->first();
                
            $procedureName = 'Restoration Tooth #' . $record->tooth_id . ' (' . $record->surface . ' - ' . $record->material . ')';

            if ($invoice) {
                $invoice->increment('amount', $cost);
                $invoice->treatments()->create([
                    'procedure' => $procedureName,
                    'cost' => $cost
                ]);
            } else {
                $invoice = \App\Models\Invoice::create([
                    'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                    'patient_id' => $patient->id,
                    'amount' => $cost,
                    'paid_amount' => 0,
                    'status' => 'Pending',
                    'issue_date' => today(),
                    'due_date' => today()->addDays(30),
                    'branch_id' => $patient->branch_id,
                ]);
                $invoice->treatments()->create([
                    'procedure' => $procedureName,
                    'cost' => $cost
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
