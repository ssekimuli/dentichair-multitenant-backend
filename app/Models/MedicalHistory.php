<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicalHistory extends Model
{
    use HasFactory;

    protected $table = 'medical_histories';

    protected $fillable = [
        'patient_id',
        'date',
        'chief_complaint',
        'history_complaint',
        'extra_oral_exam',
        'intra_oral_exam',
        'diagnosis',
        'treatment_plan',
        'treatment_given',
        'consent',
        'consent_notes',
        'treatment_note',
        'doctor_name',
        'doctor_id',
    ];

    protected $casts = [
        'date' => 'date',
        'consent' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the patient associated with this medical history record.
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * Optional relationship to the attending doctor / user.
     */
    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
