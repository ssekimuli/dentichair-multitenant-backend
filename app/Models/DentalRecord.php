<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Model;

class DentalRecord extends Model
{
    use LogsActivity;
    protected $fillable = [
        'patient_id',
        'tooth_id',
        'condition',
        'root_condition',
        'notes',
        'bridge_group_id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'patient_id',
    ];

    /**
     * Get the patient that owns the dental record.
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
