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
        'id', 'client_id', 'patient_name', 'lab', 'item', 'tooth', 'doctor_id',
        'status', 'sent_date', 'due_date', 'branch_id',
        'received_date', 'shade', 'material', 'instructions', 'cost', 'invoice_id',
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

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'doctor_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function labService(): BelongsTo
    {
        return $this->belongsTo(LabService::class);
    }
}