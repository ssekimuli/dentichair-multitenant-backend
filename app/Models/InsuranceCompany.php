<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceCompany extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'email', 'phone', 'address', 'contact_person', 'status'
    ];

    public function packages(): HasMany
    {
        return $this->hasMany(InsurancePackage::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }
}
