<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowError extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'import_job_id',
        'row_number',
        'source_column',
        'destination_field',
        'error_code',
        'error_message',
        'raw_data',
        'created_at',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'raw_data' => 'array',
        'created_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }
}
