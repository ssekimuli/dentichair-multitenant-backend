<?php

namespace App\Services\Insurance;

use App\Models\PatientInsurancePolicy;
use App\Models\Procedure;
use Carbon\Carbon;
use Exception;

class InsuranceEligibilityService
{
    public function verifyEligibility(PatientInsurancePolicy $patientPolicy): bool
    {
        if ($patientPolicy->status !== 'active') {
            throw new Exception("The selected insurance policy is not active (Status: {$patientPolicy->status}).");
        }

        if ($patientPolicy->verification_status !== 'verified') {
            throw new Exception("The insurance membership has not been verified yet.");
        }

        $now = Carbon::now();

        if ($patientPolicy->coverage_start_date && $now->lt($patientPolicy->coverage_start_date)) {
            throw new Exception("Coverage for this policy has not started yet.");
        }

        if ($patientPolicy->coverage_end_date && $now->gt($patientPolicy->coverage_end_date)) {
            throw new Exception("This insurance policy has expired.");
        }

        return true;
    }

    public function checkProcedureCoverage(PatientInsurancePolicy $patientPolicy, Procedure $procedure)
    {
        $this->verifyEligibility($patientPolicy);

        $packageProcedure = $patientPolicy->policy->package->packageProcedures()
            ->where('procedure_id', $procedure->id)
            ->where('status', 'active')
            ->first();

        if (!$packageProcedure) {
            throw new Exception("Procedure '{$procedure->name}' is not covered by this insurance package.");
        }

        return $packageProcedure;
    }
}
