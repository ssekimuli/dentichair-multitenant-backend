<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoftTissueRecord extends Model
{
    protected $fillable = [
        'patient_id',
        'date',
        'location',
        'type',
        'description',
    ];
}
