<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryOrder extends Model
{
use HasFactory;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'item', 'tooth', 'doctor_name',
        'status', 'sent_date', 'due_date', 'branch_id',
        'received_date', 'shade', 'material', 'instructions', 'invoice_id',
        'lab_service_id', 'applied_price'
    ];

    protected $casts = [
        'sent_date' => 'date',
        'due_date' => 'date',
        'received_date' => 'date',
        'applied_price' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function labService(): BelongsTo
    {
        return $this->belongsTo(LabService::class);
    }

    public function getAppliedPriceAttribute($value)
    {
        if ($value !== null && $value !== '') {
            return (float) $value;
        }

        // Auto-resolve inclusive price from the lab service if applied_price is null
        if ($this->relationLoaded('labService') && $this->labService) {
            $activePrice = $this->labService->getActivePrice($this->branch_id);
            if ($activePrice) {
                return (float) $activePrice->base_price;
            }
        } elseif ($this->lab_service_id) {
            $service = $this->labService()->first();
            $activePrice = $service?->getActivePrice($this->branch_id);
            if ($activePrice) {
                return (float) $activePrice->base_price;
            }
        }

        return 0.00;
    }
}
