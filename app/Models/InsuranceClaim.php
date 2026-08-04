<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'claim_number', 'invoice_id', 'patient_id', 'insurance_policy_id', 
        'insurance_company_id', 'submitted_amount', 'approved_amount', 
        'rejected_amount', 'paid_amount', 'outstanding_amount', 
        'submission_date', 'approval_date', 'rejection_reason', 'status', 
        'notes', 'submitted_by'
    ];

    protected $casts = [
        'submitted_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'rejected_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'submission_date' => 'date',
        'approval_date' => 'date',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(InsuranceCompany::class, 'insurance_company_id');
    }

    public function claimItems(): HasMany
    {
        return $this->hasMany(ClaimItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
