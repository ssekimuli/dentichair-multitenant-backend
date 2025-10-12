<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodontalRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id', 'tooth_id', 'pockets', 'recession', 'mobility', 'furcation', 'conditions'
    ];

    protected $casts = [
        'pockets' => 'array',
        'conditions' => 'array',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}