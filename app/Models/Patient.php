<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use HasFactory;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = [
    'first_name',
    'last_name',
    'avatar',
    'email',
    'phone',
    'address',
    'gender',
    'date_of_birth',
    'last_visit',
    'status',
    'branch_id',
    'history',
    'occlusal_records',
];


    protected $casts = [
        'date_of_birth' => 'date',
        'last_visit' => 'date',
        'occlusal_records' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function dentalRecords(): HasMany
    {
        return $this->hasMany(DentalRecord::class);
    }

    public function treatmentPlans(): HasMany
    {
        return $this->hasMany(TreatmentPlan::class)->orderBy('id', 'desc');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PatientImage::class);
    }

    public function periodontalRecords(): HasMany
    {
        return $this->hasMany(PeriodontalRecord::class);
    }

    public function softTissueRecords(): HasMany
    {
        return $this->hasMany(SoftTissueRecord::class);
    }

    public function orthodonticRecords(): HasMany
    {
        return $this->hasMany(OrthodonticRecord::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function paidInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->where('status', 'Paid');
    }

    public function unpaidInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->whereIn('status', ['Pending', 'Overdue']);
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LaboratoryOrder::class);
    }
}