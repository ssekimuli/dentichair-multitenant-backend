<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsurancePackageProcedure extends Model
{
    use HasFactory;

    protected $table = 'insurance_package_treatments';

    protected $fillable = [
        'insurance_package_id', 'procedure_id', 'coverage_type', 
        'coverage_value', 'maximum_covered_amount', 'frequency_limit', 
        'waiting_period_days', 'requires_pre_authorization', 
        'exclusion_notes', 'status'
    ];

    protected $casts = [
        'coverage_value' => 'decimal:2',
        'maximum_covered_amount' => 'decimal:2',
        'requires_pre_authorization' => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(InsurancePackage::class, 'insurance_package_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
