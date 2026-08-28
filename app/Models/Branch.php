<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
use HasFactory, SoftDeletes;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = ['id', 'name', 'address', 'currency'];

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function setting()
    {
        return $this->hasOne(BranchSetting::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'branch_id', 'id');
    }

    public function labServices()
    {
        return $this->hasMany(LabService::class);
    }

    public function labServicePrices()
    {
        return $this->hasMany(LabServicePrice::class);
    }
}