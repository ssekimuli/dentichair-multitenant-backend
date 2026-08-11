<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryOrder extends Model
{
use HasFactory;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'patient_name', 'lab', 'item', 'tooth', 'doctor_id',
        'status', 'sent_date', 'due_date', 'branch_id',
        'received_date', 'shade', 'material', 'instructions', 'cost'
    ];

    protected $casts = [
        'sent_date' => 'date',
        'due_date' => 'date',
        'received_date' => 'date',
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
}