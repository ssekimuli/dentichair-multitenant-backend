<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsurancePackage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'insurance_company_id', 'name', 'code', 'description', 
        'default_coverage_percentage', 'default_annual_limit', 
        'requires_pre_authorization', 'status'
    ];

    protected $casts = [
        'default_coverage_percentage' => 'decimal:2',
        'default_annual_limit' => 'decimal:2',
        'requires_pre_authorization' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(InsuranceCompany::class, 'insurance_company_id');
    }

    public function packageProcedures(): HasMany
    {
        return $this->hasMany(InsurancePackageProcedure::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class);
    }
}
