<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientImage extends Model
{
    protected $fillable = ['patient_id', 'url', 'description', 'category', 'date'];
}
