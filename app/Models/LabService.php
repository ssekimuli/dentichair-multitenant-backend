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

    public function getActivePrice($branchId = null): ?LabServicePrice
    {
        if ($branchId) {
            $branchPrice = $this->prices()->where('branch_id', $branchId)->where('is_active', true)->first();
            if ($branchPrice) {
                return $branchPrice;
            }
        }
        return $this->prices()->where('is_active', true)->first()
            ?? $this->prices()->first();
    }

    public function getPrices()
    {
        return $this->prices()->get();
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('service_name', 'like', "%{$search}%")
            ->orWhere('category', 'like', "%{$search}%");
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
