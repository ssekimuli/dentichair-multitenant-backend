<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationApproval extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'quotation_id',
        'approval_method',
        'decision',
        'approved_by',
        'customer_name',
        'customer_email',
        'customer_phone',
        'notes',
        'ip_address',
        'user_agent',
        'approved_at',
        'created_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }
}

