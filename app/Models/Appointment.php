<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use LogsActivity;
    use HasFactory;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = [
        'patient_name', 'patient_id', 'doctor_name', 'doctor_id',
        'treatment', 'status', 'date', 'time', 'branch_id', 'token_number', 'confirmation_token'
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'doctor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}