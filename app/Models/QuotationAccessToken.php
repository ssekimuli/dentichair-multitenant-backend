<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationAccessToken extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'quotation_id',
        'token_hash',
        'expires_at',
        'used_at',
        'revoked_at',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function isValid(): bool
    {
        if ($this->used_at !== null) {
            return false;
        }
        if ($this->revoked_at !== null) {
            return false;
        }
        return Carbon::now()->lt($this->expires_at);
    }
}

