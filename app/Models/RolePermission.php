<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    use LogsActivity;
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'permissions',
        'description'
    ];

    protected $casts = [
        'permissions' => 'array'
    ];

    /**
     * Get employees with this role
     */
    public function employees()
    {
        return $this->hasMany(Employee::class, 'role', 'id');
    }
}