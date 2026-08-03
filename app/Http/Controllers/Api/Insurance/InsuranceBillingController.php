<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use App\Services\Insurance\InsuranceBillingService;
use App\Models\PatientInsurancePolicy;
use Illuminate\Http\Request;

class InsuranceBillingController extends Controller
{
    protected $billingService;

    public function __construct(InsuranceBillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    public function estimate(Request $request, $patientPolicyId)
    {
        $request->validate([
            'procedures' => 'required|array',
            'procedures.*.procedure_id' => 'required|exists:treatments,id',
            'procedures.*.quantity' => 'nullable|integer|min:1',
        ]);

        $patientPolicy = PatientInsurancePolicy::with('policy.package.packageProcedures')->findOrFail($patientPolicyId);
        
        $estimate = $this->billingService->calculateEstimate($patientPolicy, $request->procedures);

        return response()->json(['data' => $estimate]);
    }

    public function createInvoice(Request $request, $patientPolicyId)
    {
        $request->validate([
            'patient_id' => 'required|exists:patients,id',
            'procedures' => 'required|array',
        ]);

        $patientPolicy = PatientInsurancePolicy::findOrFail($patientPolicyId);
        
        $invoice = $this->billingService->createInsuredInvoice(
            $patientPolicy, 
            $request->procedures, 
            $request->patient_id
        );

        return response()->json(['message' => 'Invoice created successfully.', 'invoice_id' => $invoice->id]);
    }
}
