<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrthodonticRecord extends Model
{
    protected $fillable = [
        'patient_id',
        'date',
        'appliance',
        'appliance_location',
        'treatment_phase',
        'wire_size',
        'wire_size_upper',
        'wire_size_lower',
        'archwire_material',
        'elastics',
        'compliance',
        'oral_hygiene',
        'bracket_changes',
        'broken_brackets',
        'notes',
        'next_visit_goals',
    ];

    protected $casts = [
        'bracket_changes' => 'array',
        'broken_brackets' => 'array',
    ];
}
