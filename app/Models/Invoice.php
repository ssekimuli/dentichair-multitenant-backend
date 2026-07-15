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
        'issue_date', 'due_date', 'branch_id', 'payment_method', 'medicines'
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'medicines' => 'array',
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