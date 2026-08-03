<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceClaimDocument extends Model
{
    protected $fillable = [
        'insurance_claim_id',
        'url',
        'description',
    ];

    public function insuranceClaim()
    {
        return $this->belongsTo(InsuranceClaim::class);
    }
}
