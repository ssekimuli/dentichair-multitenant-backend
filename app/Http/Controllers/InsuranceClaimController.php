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
        return response()->json(\App\Models\InsuranceClaim::with(['patientInsurance.patient', 'invoice'])->latest()->get());
    }

    public function store(Request $request)
    {
        $existingClaim = null;
        if ($request->filled('invoice_id')) {
            $existingClaim = \App\Models\InsuranceClaim::where('invoice_id', $request->invoice_id)->first();
        }

        $validated = $request->validate([
            'patient_insurance_id' => 'required|exists:patient_insurances,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'claim_number' => 'required|string|unique:insurance_claims,claim_number' . ($existingClaim ? ',' . $existingClaim->id : ''),
            'total_amount' => 'required|numeric',
            'claimed_amount' => 'required|numeric',
            'approved_amount' => 'nullable|numeric',
            'status' => 'nullable|in:pending,submitted,paid,denied',
            'notes' => 'nullable|string',
            'date_submitted' => 'nullable|date',
            'date_processed' => 'nullable|date',
        ]);

        if ($existingClaim) {
            $existingClaim->update($validated);
            $claim = $existingClaim;
            $statusCode = 200;
        } else {
            $claim = \App\Models\InsuranceClaim::create($validated);
            $statusCode = 201;
        }

        $claim->load(['patientInsurance.patient', 'invoice']);
        
        return response()->json($claim, $statusCode);
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
            'write_off_amount' => 'nullable|numeric',
            'status' => 'nullable|in:pending,submitted,paid,denied',
            'notes' => 'nullable|string',
            'date_submitted' => 'nullable|date',
            'date_processed' => 'nullable|date',
        ]);

        $oldStatus = $claim->status;
        $claim->update($validated);
        
        // If status changed to paid
        if (isset($validated['status']) && $validated['status'] === 'paid' && $oldStatus !== 'paid') {
            $claim->load(['patientInsurance.patient']);
            $providerName = $claim->patientInsurance ? $claim->patientInsurance->provider_name : 'Insurance';
            $amount = $validated['approved_amount'] ?? $claim->claimed_amount;
            $writeOff = $validated['write_off_amount'] ?? 0;
            $patientBalance = $claim->total_amount - $amount - $writeOff;

            if ($claim->invoice_id) {
                // Claim is linked to an invoice
                $invoice = \App\Models\Invoice::find($claim->invoice_id);
                if ($invoice) {
                    $invoice->transactions()->create([
                        'amount' => $amount,
                        'method' => 'Insurance (' . $providerName . ')',
                        'date' => now()->format('Y-m-d'),
                    ]);

                    $newPaidAmount = $invoice->paid_amount + $amount;
                    $newInvoiceAmount = $invoice->amount;

                    if ($writeOff > 0) {
                        $newInvoiceAmount = max(0, $invoice->amount - $writeOff);
                        $invoice->discount = ($invoice->discount ?? 0) + $writeOff;
                    }

                    $invoice->update([
                        'amount' => $newInvoiceAmount,
                        'paid_amount' => $newPaidAmount,
                        'status' => $newPaidAmount >= $newInvoiceAmount ? 'Paid' : 'Pending',
                        'payment_method' => 'Insurance',
                    ]);
                }
            } else {
                // Claim is NOT linked to an invoice. Bill the patient for any remaining balance.
                if ($patientBalance > 0 && $claim->patientInsurance && $claim->patientInsurance->patient) {
                    $patient = $claim->patientInsurance->patient;
                    
                    $newInvoice = \App\Models\Invoice::create([
                        'patient_id' => $patient->id,
                        'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                        'amount' => $patientBalance,
                        'paid_amount' => 0,
                        'status' => 'Pending',
                        'issue_date' => now()->format('Y-m-d'),
                        'due_date' => now()->addDays(30)->format('Y-m-d'),
                        'branch_id' => $patient->branch_id ?? \App\Models\Branch::first()->id,
                    ]);

                    $newInvoice->otherCharges()->create([
                        'description' => 'Patient Balance for Insurance Claim ' . $claim->claim_number,
                        'amount' => $patientBalance,
                    ]);

                    // Link the newly generated invoice back to the claim
                    $claim->invoice_id = $newInvoice->id;
                    $claim->save();
                }
            }
        }

        return response()->json($claim);
    }

    public function destroy($id)
    {
        \App\Models\InsuranceClaim::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
