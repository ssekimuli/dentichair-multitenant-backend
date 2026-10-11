<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XrayRequest extends Model
{
    use HasFactory;

    protected $table = 'xray_requests';

    protected $fillable = [
        'client_uuid',
        'patient',
        'patient_id',
        'facility',
        'amount',
        'xray_type',
        'tooth_region',
        'clinical_notes',
        'doctor_name',
        'doctor_id',
        'branch_id',
        'invoice_id',
        'status',
        'request_date',
        'completed_date',
        'report_findings',
        'attachments',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_date' => 'date',
        'completed_date' => 'date',
        'attachments' => 'array',
    ];

    /**
     * Optional relationship to registered patient profile
     */
    public function patientRecord(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'doctor_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
