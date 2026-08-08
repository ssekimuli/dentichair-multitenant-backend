<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CloudflareR2Service;

class InsuranceClaimController extends Controller
{
    public function stats()
    {
        $totalClaims = \App\Models\InsuranceClaim::count();
        $paidClaimsAmount = \App\Models\InsuranceClaim::where('status', 'Paid')->sum('approved_amount') ?? 0;
        $pendingCount = \App\Models\InsuranceClaim::whereIn('status', ['Draft', 'Submitted', 'Under Review'])->count();
        
        return response()->json([
            'total_claims' => $totalClaims,
            'paid_amount' => $paidClaimsAmount,
            'pending_count' => $pendingCount
        ]);
    }

    public function index()
    {
        try {
            $claims = \App\Models\InsuranceClaim::with(['patient', 'policy.package.company', 'invoice'])->latest()->get();
            // format claims for the frontend which expects patient_insurance.patient, etc
            $claims->transform(function ($claim) {
                $claim->patient_insurance = [
                    'patient' => $claim->patient,
                    'provider_name' => $claim->policy->package->company->name ?? 'Unknown',
                    'policy_number' => $claim->policy->policy_number ?? 'Unknown'
                ];
                $claim->total_amount = $claim->invoice ? $claim->invoice->amount : $claim->submitted_amount;
                $claim->claimed_amount = $claim->submitted_amount;
                return $claim;
            });
            return response()->json($claims);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error fetching insurance claims: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'An error occurred while fetching insurance claims',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $existingClaim = null;
            if ($request->filled('invoice_id')) {
                $existingClaim = \App\Models\InsuranceClaim::where('invoice_id', $request->invoice_id)->first();
            }

            $validated = $request->validate([
                'patient_insurance_id' => 'required|exists:patient_insurance_policies,id',
                'invoice_id' => 'nullable|exists:invoices,id',
                'claim_number' => 'required|string|unique:insurance_claims,claim_number' . ($existingClaim ? ',' . $existingClaim->id : ''),
                'total_amount' => 'required|numeric',
                'claimed_amount' => 'required|numeric',
                'approved_amount' => 'nullable|numeric',
                'status' => 'nullable|in:Draft,Submitted,Under Review,Approved,Partially Approved,Rejected,Paid,Cancelled',
                'notes' => 'nullable|string',
                'date_submitted' => 'nullable|date',
                'date_processed' => 'nullable|date',
            ]);

            $pivot = \Illuminate\Support\Facades\DB::table('patient_insurance_policies')
                ->join('insurance_policies', 'insurance_policies.id', '=', 'patient_insurance_policies.insurance_policy_id')
                ->join('insurance_packages', 'insurance_packages.id', '=', 'insurance_policies.insurance_package_id')
                ->where('patient_insurance_policies.id', $request->patient_insurance_id)
                ->select('patient_insurance_policies.patient_id', 'patient_insurance_policies.insurance_policy_id', 'insurance_packages.insurance_company_id')
                ->first();

            if (!$pivot) {
                return response()->json(['message' => 'Insurance policy mapping not found'], 404);
            }

            $claimData = [
                'patient_id' => $pivot->patient_id,
                'insurance_policy_id' => $pivot->insurance_policy_id,
                'insurance_company_id' => $pivot->insurance_company_id,
                'claim_number' => $validated['claim_number'],
                'invoice_id' => $validated['invoice_id'] ?? null,
                'submitted_amount' => $validated['claimed_amount'],
                'status' => $validated['status'] ?? 'Draft',
                'notes' => $validated['notes'] ?? null,
            ];

            if ($existingClaim) {
                $existingClaim->update($claimData);
                $claim = $existingClaim;
                $statusCode = 200;
            } else {
                $claim = \App\Models\InsuranceClaim::create($claimData);
                $statusCode = 201;
            }

            $claim->load(['policy', 'patient', 'invoice']);
            
            return response()->json($claim, $statusCode);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error creating insurance claim: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'message' => 'An error occurred while creating the insurance claim',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $claim = \App\Models\InsuranceClaim::with(['patient', 'policy.package.company'])->findOrFail($id);

            if ($claim->invoice) {
                $claim->invoice->load(['treatments' => function ($query) {
                    $query->where('insurance_amount', '>', 0);
                }]);
            }

            $claim->patient_insurance = [
                'patient' => $claim->patient,
                'provider_name' => $claim->policy->package->company->name ?? 'Unknown',
                'policy_number' => $claim->policy->policy_number ?? 'Unknown'
            ];
            $claim->total_amount = $claim->invoice ? $claim->invoice->amount : $claim->submitted_amount;
            $claim->claimed_amount = $claim->submitted_amount;
            return response()->json($claim);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Insurance claim not found'], 404);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error fetching insurance claim: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'id' => $id
            ]);
            return response()->json([
                'message' => 'An error occurred while fetching the insurance claim',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $claim = \App\Models\InsuranceClaim::findOrFail($id);
            
            $validated = $request->validate([
                'invoice_id' => 'nullable|exists:invoices,id',
                'claim_number' => 'sometimes|string|unique:insurance_claims,claim_number,'.$id,
                'total_amount' => 'sometimes|numeric',
                'claimed_amount' => 'sometimes|numeric',
                'approved_amount' => 'nullable|numeric',
                'write_off_amount' => 'nullable|numeric',
                'status' => 'nullable|in:Draft,Submitted,Under Review,Approved,Partially Approved,Rejected,Paid,Cancelled',
                'notes' => 'nullable|string',
                'date_submitted' => 'nullable|date',
                'date_processed' => 'nullable|date',
            ]);

            $oldStatus = $claim->status;
            $claim->update($validated);
            
            // If status changed to paid
            if (isset($validated['status']) && strtolower($validated['status']) === 'paid' && strtolower($oldStatus) !== 'paid') {
                $claim->load(['patient', 'policy.package.company']);
                $providerName = $claim->policy && $claim->policy->package && $claim->policy->package->company 
                    ? $claim->policy->package->company->name 
                    : 'Insurance';
                
                $amount = $validated['approved_amount'] ?? $claim->submitted_amount;
                $writeOff = $validated['write_off_amount'] ?? 0;
                $totalAmount = $claim->invoice_id ? (\App\Models\Invoice::find($claim->invoice_id)->amount ?? $claim->submitted_amount) : $claim->submitted_amount;
                $patientBalance = $totalAmount - $amount - $writeOff;

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
                    if ($patientBalance > 0 && $claim->patient) {
                        $patient = $claim->patient;
                        
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

            $claim->load(['patient', 'policy.package.company', 'invoice.treatments']);
            $claim->patient_insurance = [
                'patient' => $claim->patient,
                'provider_name' => $claim->policy->package->company->name ?? 'Unknown',
                'policy_number' => $claim->policy->policy_number ?? 'Unknown'
            ];
            $claim->total_amount = $claim->invoice ? $claim->invoice->amount : $claim->submitted_amount;
            $claim->claimed_amount = $claim->submitted_amount;

            return response()->json($claim);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Insurance claim not found'], 404);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating insurance claim: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'id' => $id
            ]);
            return response()->json([
                'message' => 'An error occurred while updating the insurance claim',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        \App\Models\InsuranceClaim::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    public function uploadDocument(Request $request, $id)
    {
        $claim = \App\Models\InsuranceClaim::findOrFail($id);

        $request->validate([
            'description' => 'required|string',
            'file' => 'required|file|max:20480',
        ]);

        $service = app(CloudflareR2Service::class);
        $url = $service->upload($request->file('file'), 'insurance-claims');

        $document = $claim->documents()->create([
            'url' => $url,
            'description' => $request->description,
        ]);

        return response()->json($document, 201);
    }

    public function deleteDocument($id, $documentId)
    {
        $claim = \App\Models\InsuranceClaim::findOrFail($id);
        $document = $claim->documents()->findOrFail($documentId);

        $service = app(CloudflareR2Service::class);
        $service->delete($document->url);
        
        $document->delete();

        return response()->json(['message' => 'Document deleted successfully']);
    }
}
