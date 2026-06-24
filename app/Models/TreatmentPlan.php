<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreatmentPlan extends Model
{
    //
    // procedure
     protected $fillable = ['procedure', 'cost', 'treatment_id', 'appointment_id', 'patient_id', 'doctor_id', 'branch_id', 'status', 'notes', 'date', 'time', 'token_number'];

}
