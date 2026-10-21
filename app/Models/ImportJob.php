<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ImportJob extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'branch_id',
        'table_name',
        'file_name',
        'file_path',
        'file_type',
        'sheet_name',
        'status',
        'mode',
        'error_mode',
        'update_key',
        'total_rows',
        'processed_rows',
        'imported_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'mapping',
        'options',
        'error_summary',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'mapping' => 'array',
        'options' => 'array',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'imported_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportRowError::class, 'import_job_id');
    }

    public function progressPercentage(): int
    {
        if ($this->total_rows <= 0) {
            return $this->status === 'completed' ? 100 : 0;
        }

        $pct = (int) round(($this->processed_rows / $this->total_rows) * 100);
        return min(100, max(0, $pct));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }
}
