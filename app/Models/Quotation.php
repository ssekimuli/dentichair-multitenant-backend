<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'quotation_number',
        'customer_type',
        'patient_id',
        'lab_client_id',
        'dentist_id',
        'lab_order_id',
        'branch_id',
        'source_type',
        'source_id',
        'quotation_date',
        'valid_until',
        'currency',
        'subtotal',
        'discount_type',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total',
        'status',
        'notes',
        'terms_conditions',
        'expected_delivery_date',
        'approved_at',
        'rejected_at',
        'converted_invoice_id',
        'original_quotation_id',
        'revision_number',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'expected_delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'revision_number' => 'integer',
    ];

    protected $appends = [
        'customer_name',
        'customer_email',
        'customer_phone',
        'is_expired',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function labClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'lab_client_id');
    }

    public function dentist(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'dentist_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class, 'lab_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(QuotationApproval::class)->orderBy('id', 'desc');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(QuotationStatusHistory::class)->orderBy('id', 'desc');
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(QuotationAccessToken::class)->orderBy('id', 'desc');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    public function originalQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'original_quotation_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Quotation::class, 'original_quotation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }

    public function getCustomerNameAttribute(): ?string
    {
        if ($this->customer_type === 'patient') {
            if ($this->relationLoaded('patient') && $this->patient) {
                return trim(($this->patient->first_name ?? '') . ' ' . ($this->patient->last_name ?? ''));
            }
            return $this->patient ? trim(($this->patient->first_name ?? '') . ' ' . ($this->patient->last_name ?? '')) : null;
        }

        if ($this->relationLoaded('labClient') && $this->labClient) {
            return $this->labClient->name;
        }
        return $this->labClient?->name;
    }

    public function getCustomerEmailAttribute(): ?string
    {
        if ($this->customer_type === 'patient') {
            return $this->patient?->email;
        }
        return $this->labClient?->email;
    }

    public function getCustomerPhoneAttribute(): ?string
    {
        if ($this->customer_type === 'patient') {
            return $this->patient?->phone;
        }
        return $this->labClient?->phone;
    }

    public function getIsExpiredAttribute(): bool
    {
        if ($this->status === 'expired') {
            return true;
        }
        if (in_array($this->status, ['draft', 'sent']) && $this->valid_until) {
            return Carbon::parse($this->valid_until)->endOfDay()->isPast();
        }
        return false;
    }

    public function canBeEdited(): bool
    {
        return $this->status === 'draft';
    }

    public function canBeApproved(): bool
    {
        if ($this->status !== 'sent') {
            return false;
        }
        return !$this->is_expired;
    }

    public function canBeConverted(): bool
    {
        return $this->status === 'approved' && is_null($this->converted_invoice_id);
    }
}

