<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class LabServicePrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'lab_service_id',
        'lab',
        'base_price',
        'branch_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_price' => 'decimal:2',
    ];

    public function labService(): BelongsTo
    {
        return $this->belongsTo(LabService::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForBranch(Builder $query, $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeSearch(Builder $query, $search): Builder
    {
        return $query->where('lab', 'like', "%{$search}%")
            ->orWhereHas('labService', function ($q) use ($search) {
                $q->where('service_name', 'like', "%{$search}%");
            });
    }

    public function scopeByLab(Builder $query, $lab): Builder
    {
        return $query->where('lab', $lab);
    }

    public function scopePriceBetween(Builder $query, $min, $max): Builder
    {
        return $query->whereBetween('base_price', [$min, $max]);
    }
}
