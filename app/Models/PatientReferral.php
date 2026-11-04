<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientReferral extends Model
{
    use HasFactory;

    protected $table = 'patient_referrals';

    protected $fillable = [
        'client_uuid',
        'patient_id',
        'branch_id',
        'patient_name',
        'patient_number',
        'sex',
        'age',
        'first_visit_date',
        'referral_date',
        'referred_to',
        'target_email',
        'target_phone',
        'from_health_unit',
        'unit_date',
        'history_and_symptoms',
        'investigations_done',
        'diagnosis',
        'treatment_given',
        'reason_for_referral',
        'clinician_name',
        'clinician_phone',
        'clinician_signature',
        'further_investigations',
        'referral_site_diagnosis',
        'referral_site_treatment',
        'surveillance_continued',
        'remarks',
        'referral_site_clinician',
        'referral_site_phone',
        'referral_site_signature',
        'status',
    ];

    protected $casts = [
        'first_visit_date' => 'date:Y-m-d',
        'referral_date' => 'date:Y-m-d',
        'unit_date' => 'date:Y-m-d',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}

