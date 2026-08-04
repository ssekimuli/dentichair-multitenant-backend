<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsurancePolicy extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'insurance_package_id', 'policy_number', 'policy_type', 
        'employer_or_group_name', 'coverage_start_date', 'coverage_end_date', 
        'total_coverage_limit', 'used_amount', 'remaining_balance', 'status'
    ];

    protected $casts = [
        'coverage_start_date' => 'date',
        'coverage_end_date' => 'date',
        'total_coverage_limit' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(InsurancePackage::class, 'insurance_package_id');
    }

    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'patient_insurance_policies')
                    ->using(PatientInsurancePolicy::class)
                    ->withPivot([
                        'id', 'subscriber_id', 'group_number', 
                        'relationship_to_subscriber', 'subscriber_name',
                        'is_primary', 'status'
                    ])
                    ->withTimestamps();
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }
}
