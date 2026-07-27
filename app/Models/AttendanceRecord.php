<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
protected $fillable = [
        'id',
        'employee_id',
        'employee_name',
        'date',
        'status',
        'check_in',
        'check_out',
        'branch_id',
    ];

    // Relationship to Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // Relationship to Branch
    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
