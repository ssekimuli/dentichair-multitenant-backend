<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BranchSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'clinic_name', 'sms_balance', 'whatsapp_balance', 'notifications'
    ];

    protected $casts = [
        'notifications' => 'array',
        'sms_balance' => 'integer',
        'whatsapp_balance' => 'integer',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
