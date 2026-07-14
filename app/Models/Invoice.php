<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = [
        'patient_name', 'patient_id', 'amount', 'paid_amount', 'status',
        'issue_date', 'due_date', 'branch_id', 'payment_method'
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'Paid');
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', ['Pending', 'Overdue']);
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(InvoiceTreatment::class);
    }

    public function otherCharges(): HasMany
    {
        return $this->hasMany(InvoiceOtherCharge::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}