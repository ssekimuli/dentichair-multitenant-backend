<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestorativeRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'tooth_id',
        'surface',
        'material',
        'notes',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
