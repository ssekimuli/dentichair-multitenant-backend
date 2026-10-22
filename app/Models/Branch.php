<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
use HasFactory, SoftDeletes;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = ['id', 'name', 'address', 'currency', 'logo'];

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function setting()
    {
        return $this->hasOne(BranchSetting::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'branch_id', 'id');
    }

    public function labServices()
    {
        return $this->hasMany(LabService::class);
    }

    public function labServicePrices()
    {
        return $this->hasMany(LabServicePrice::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function laboratoryOrders(): HasMany
    {
        return $this->hasMany(LaboratoryOrder::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function staffAdvances(): HasMany
    {
        return $this->hasMany(StaffAdvance::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }
}