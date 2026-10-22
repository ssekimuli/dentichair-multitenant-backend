<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'details',
        'business_status',
        'business_email',
        'joined_date',
        'subscription_expire_date',
    ];
}
