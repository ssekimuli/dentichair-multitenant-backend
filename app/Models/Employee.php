<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class Employee extends Model
{
      use HasFactory, HasApiTokens;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'avatar', 'email', 'role', 'status', 
        'salary', 'hire_date', 'branch_id','password'
    ];
    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'salary' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'doctor_id');
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function attendance(): HasMany
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
}