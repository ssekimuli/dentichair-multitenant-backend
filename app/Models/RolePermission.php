<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    use HasFactory;

    // protected $keyType = 'string';
    // public $incrementing = false;

    protected $fillable = ['id', 'name', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];
}