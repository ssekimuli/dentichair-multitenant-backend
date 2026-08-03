<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientInsurancePolicy extends Pivot
{
    protected $table = 'patient_insurance_policies';

    public $incrementing = true;

    protected $casts = [
        'coverage_start_date' => 'date',
        'coverage_end_date' => 'date',
        'individual_coverage_limit' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'requires_approval' => 'boolean',
        'is_primary' => 'boolean',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function principalPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'principal_patient_id');
    }
}
