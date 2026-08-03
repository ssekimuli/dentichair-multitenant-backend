<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Model;

class SoftTissueRecord extends Model
{
    use LogsActivity;
    protected $fillable = [
        'patient_id',
        'date',
        'location',
        'type',
        'description',
    ];
}
