<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Model;

class EndodonticRecord extends Model
{
    use LogsActivity;
    protected $fillable = [
        'patient_id',
        'date',
        'tooth_id',
        'pulpal_diagnosis',
        'periapical_diagnosis',
        'canals',
        'obturation_material',
        'post_type',
        'notes',
    ];

    protected $casts = [
        'canals' => 'array',
    ];
}
