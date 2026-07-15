<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrthodonticRecord extends Model
{
    protected $fillable = [
        'patient_id',
        'date',
        'appliance',
        'wire_size',
        'bracket_changes',
        'notes',
    ];

    protected $casts = [
        'bracket_changes' => 'array',
    ];
}
