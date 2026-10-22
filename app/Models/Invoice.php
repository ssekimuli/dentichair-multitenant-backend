<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;
    use LogsActivity;
    use HasFactory;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = [
        'receipt_number', 'patient_name', 'patient_id', 'amount', 'paid_amount', 'status',
        'issue_date', 'due_date', 'branch_id', 'payment_method', 'medicines', 'category',
        'discount', 'tax', 'insurance_coverage',
        'patient_responsibility', 'insurance_responsibility',
        'patient_paid_amount', 'insurance_paid_amount',
        'patient_balance', 'insurance_balance'
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

    protected $appends = ['lab_order_details', 'xray_request_details'];

    protected static function booted()
    {
        static::creating(function ($invoice) {
            if (empty($invoice->receipt_number)) {
                $prefix = 'REC-';
                try {
                    $setting = \App\Models\GlobalSetting::getSettings();
                    if (!empty($setting->receipt_prefix)) {
                        $prefix = rtrim($setting->receipt_prefix, '-') . '-';
                    }
                } catch (\Throwable $e) {}
                $invoice->receipt_number = $prefix . strtoupper(\Illuminate\Support\Str::random(8));
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

    public function getLabOrderDetailsAttribute()
    {
        $order = $this->laboratoryOrder;
        if (!$order) {
            return null;
        }

        return [
            'id' => $order->id,
            'client_id' => $order->client_id,
            'client_name' => $order->client->name ?? null,
            'patient_name' => $order->patient_name,
            'lab' => $order->lab,
            'item' => $order->item,
            'tooth' => $order->tooth,
            'status' => $order->status,
            'sent_date' => $order->sent_date?->format('Y-m-d'),
            'due_date' => $order->due_date?->format('Y-m-d'),
            'received_date' => $order->received_date?->format('Y-m-d'),
            'shade' => $order->shade,
            'material' => $order->material,
            'instructions' => $order->instructions,
            'cost' => $order->cost,
            'doctor_id' => $order->doctor_id,
            'doctor_name' => $order->doctor->name ?? null,
        ];
    }

    public function getXrayRequestDetailsAttribute()
    {
        $xray = $this->xrayRequest;
        if (!$xray) {
            return null;
        }

        return [
            'id'           => $xray->id,
            'patient'      => $xray->patient,
            'facility'     => $xray->facility,
            'xray_type'    => $xray->xray_type,
            'tooth_region' => $xray->tooth_region,
            'status'       => $xray->status,
            'request_date' => $xray->request_date?->format('Y-m-d'),
            'amount'       => $xray->amount,
            'doctor_name'  => $xray->doctor_name,
        ];
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

    public function laboratoryOrder(): HasOne
    {
        return $this->hasOne(LaboratoryOrder::class);
    }

    public function xrayRequest(): HasOne
    {
        return $this->hasOne(XrayRequest::class, 'invoice_id');
    }


    public function getMedicinesAttribute($value)
    {
        $items = is_string($value) ? json_decode($value, true) : $value;
        $items = $items ?: [];
        if (empty($items)) {
            return [];
        }

        // Check if this is a lab order reference (not actual medicines)
        if (is_array($items) && isset($items['lab_order_id']) && !isset($items[0])) {
            return [];
        }

        // Ensure items is an array
        if (!is_array($items)) {
            return [];
        }

        $medicineIds = collect($items)->pluck('medicine_id')->filter()->unique();
        $unitIds = collect($items)->pluck('medicine_unit_id')->filter()->unique();

        if ($medicineIds->isEmpty() && $unitIds->isEmpty()) {
            return [];
        }

        $medicines = Medicine::whereIn('id', $medicineIds)->get()->keyBy('id');
        $units = MedicineUnit::whereIn('id', $unitIds)->get()->keyBy('id');

        return array_map(function ($item) use ($medicines, $units) {
            if (!is_array($item)) {
                return $item;
            }

            $med = isset($item['medicine_id']) ? $medicines->get($item['medicine_id']) : null;
            $unit = isset($item['medicine_unit_id']) ? $units->get($item['medicine_unit_id']) : null;

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