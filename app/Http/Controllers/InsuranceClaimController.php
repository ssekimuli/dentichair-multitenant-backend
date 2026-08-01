<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InsuranceClaimController extends Controller
{
    public function stats()
    {
        $totalClaims = \App\Models\InsuranceClaim::count();
        $paidClaimsAmount = \App\Models\InsuranceClaim::where('status', 'paid')->sum('approved_amount') ?? 0;
        $pendingCount = \App\Models\InsuranceClaim::where('status', 'pending')->count();
        
        return response()->json([
            'total_claims' => $totalClaims,
            'paid_amount' => $paidClaimsAmount,
            'pending_count' => $pendingCount
        ]);
    }

    public function index()
    {
        return response()->json(\App\Models\InsuranceClaim::with(['patientInsurance.patient', 'invoice'])->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_insurance_id' => 'required|exists:patient_insurances,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'claim_number' => 'required|string|unique:insurance_claims,claim_number',
            'total_amount' => 'required|numeric',
            'claimed_amount' => 'required|numeric',
            'approved_amount' => 'nullable|numeric',
            'status' => 'nullable|in:pending,submitted,paid,denied',
            'notes' => 'nullable|string',
            'date_submitted' => 'nullable|date',
            'date_processed' => 'nullable|date',
        ]);

        $claim = \App\Models\InsuranceClaim::create($validated);
        $claim->load(['patientInsurance.patient', 'invoice']);
        
        return response()->json($claim, 201);
    }

    public function show($id)
    {
        $claim = \App\Models\InsuranceClaim::with(['patientInsurance.patient', 'invoice'])->findOrFail($id);
        return response()->json($claim);
    }

    public function update(Request $request, $id)
    {
        $claim = \App\Models\InsuranceClaim::findOrFail($id);
        
        $validated = $request->validate([
            'invoice_id' => 'nullable|exists:invoices,id',
            'claim_number' => 'sometimes|string|unique:insurance_claims,claim_number,'.$id,
            'total_amount' => 'sometimes|numeric',
            'claimed_amount' => 'sometimes|numeric',
            'approved_amount' => 'nullable|numeric',
            'status' => 'nullable|in:pending,submitted,paid,denied',
            'notes' => 'nullable|string',
            'date_submitted' => 'nullable|date',
            'date_processed' => 'nullable|date',
        ]);

        $claim->update($validated);
        return response()->json($claim);
    }

    public function destroy($id)
    {
        \App\Models\InsuranceClaim::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
