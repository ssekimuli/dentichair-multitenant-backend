<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientInsurance extends Model
{
    protected $fillable = [
        'patient_id',
        'provider_name',
        'policy_number',
        'group_number',
        'plan_type',
        'coverage_start',
        'coverage_end',
        'is_active',
    ];

    protected $casts = [
        'coverage_start' => 'date',
        'coverage_end' => 'date',
        'is_active' => 'boolean',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function claims()
    {
        return $this->hasMany(InsuranceClaim::class);
    }
}
