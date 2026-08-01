<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceClaim extends Model
{
    protected $fillable = [
        'patient_insurance_id',
        'invoice_id',
        'claim_number',
        'total_amount',
        'claimed_amount',
        'approved_amount',
        'status',
        'notes',
        'date_submitted',
        'date_processed',
    ];

    protected $casts = [
        'date_submitted' => 'date',
        'date_processed' => 'date',
        'total_amount' => 'decimal:2',
        'claimed_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
    ];

    public function patientInsurance()
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
