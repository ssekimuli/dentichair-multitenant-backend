<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabService extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'service_name',
        'category',
        'is_active',
        'branch_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(LabServicePrice::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LaboratoryOrder::class, 'lab_service_id');
    }

    public function getActivePrice(): ?LabServicePrice
    {
        return $this->prices()->where('is_active', true)->first();
    }

    public function getPrices()
    {
        return $this->prices()->get();
    }
}
