<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'insurance_claim_id', 'invoice_item_id', 'procedure_id', 
        'claimed_amount', 'approved_amount', 'rejected_amount', 
        'paid_amount', 'rejection_reason', 'status'
    ];

    protected $casts = [
        'claimed_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'rejected_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(InsuranceClaim::class, 'insurance_claim_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceTreatment::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
