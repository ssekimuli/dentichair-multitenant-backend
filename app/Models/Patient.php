<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use LogsActivity;
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
    'next_visit',
    'status',
    'branch_id',
    'history',
    'occlusal_records',
    'nationality',
];


    protected $casts = [
        'date_of_birth' => 'date',
        'last_visit' => 'date',
        'next_visit' => 'date',
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

    public function endodonticRecords(): HasMany
    {
        return $this->hasMany(EndodonticRecord::class);
    }

    public function restorativeRecords(): HasMany
    {
        return $this->hasMany(RestorativeRecord::class);
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

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class)->orderBy('id', 'desc');
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    public function insurancePolicies()
    {
        return $this->belongsToMany(InsurancePolicy::class, 'patient_insurance_policies')
                    ->using(PatientInsurancePolicy::class)
                    ->withPivot([
                        'id', 'subscriber_id', 'group_number', 
                        'relationship_to_subscriber', 'subscriber_name',
                        'is_primary', 'status'
                    ])
                    ->withTimestamps();
    }

    public function primaryInsurancePolicy()
    {
        return $this->insurancePolicies()->wherePivot('is_primary', true)->wherePivot('status', 'active');
    }

    protected static function booted(): void
    {
        static::updated(function (Patient $patient) {
            if ($patient->isDirty('next_visit') && $patient->next_visit) {
                $patient->createNextVisitAppointment();
            }
        });

        static::created(function (Patient $patient) {
            if ($patient->next_visit) {
                $patient->createNextVisitAppointment();
            }
        });
    }

    public function createNextVisitAppointment(): void
    {
        if (!$this->next_visit) {
            return;
        }

        $existingAppointment = Appointment::where('patient_id', $this->id)
            ->whereDate('date', $this->next_visit)
            ->where('status', '!=', 'Cancelled')
            ->first();

        if ($existingAppointment) {
            return;
        }

        Appointment::create([
            'patient_id' => $this->id,
            'patient_name' => $this->first_name . ' ' . $this->last_name,
            'date' => $this->next_visit,
            'time' => '09:00',
            'status' => 'Scheduled',
            'branch_id' => $this->branch_id,
            'treatment' => 'Follow-up Visit',
        ]);

        ActivityLog::log(
            'Next Visit Appointment Created',
            "Appointment automatically created for next visit on {$this->next_visit->format('Y-m-d')}",
            ['patient_id' => $this->id, 'appointment_date' => $this->next_visit]
        );
    }
}