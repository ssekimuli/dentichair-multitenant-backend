<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use LogsActivity;
    use HasFactory;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = [
        'receipt_number', 'patient_name', 'patient_id', 'amount', 'paid_amount', 'status',
        'issue_date', 'due_date', 'branch_id', 'payment_method', 'medicines',
        'discount', 'tax', 'insurance_coverage'
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'patient_responsibility' => 'decimal:2',
        'insurance_responsibility' => 'decimal:2',
        'patient_paid_amount' => 'decimal:2',
        'insurance_paid_amount' => 'decimal:2',
        'patient_balance' => 'decimal:2',
        'insurance_balance' => 'decimal:2',
        'medicines' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($invoice) {
            if (empty($invoice->receipt_number)) {
                $invoice->receipt_number = 'REC-' . strtoupper(\Illuminate\Support\Str::random(8));
            }
        });
    }

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

    public function insuranceClaims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }

    public function getMedicinesAttribute($value)
    {
        $items = is_string($value) ? json_decode($value, true) : $value;
        $items = $items ?: [];
        if (empty($items)) {
            return [];
        }

        $medicineIds = collect($items)->pluck('medicine_id')->unique();
        $unitIds = collect($items)->pluck('medicine_unit_id')->unique();

        $medicines = Medicine::whereIn('id', $medicineIds)->get()->keyBy('id');
        $units = MedicineUnit::whereIn('id', $unitIds)->get()->keyBy('id');

        return array_map(function ($item) use ($medicines, $units) {
            $med = $medicines->get($item['medicine_id']);
            $unit = $units->get($item['medicine_unit_id']);
            
            $item['medicine'] = $med ? [
                'id' => $med->id,
                'name' => $med->name,
                'description' => $med->description,
                'category' => $med->category,
            ] : null;
            
            $item['unit'] = $unit ? [
                'id' => $unit->id,
                'unit_name' => $unit->unit_name,
                'price' => (float)$unit->price,
            ] : null;
            
            return $item;
        }, $items);
    }
}