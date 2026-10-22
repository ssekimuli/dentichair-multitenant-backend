<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlobalSetting extends Model
{
use HasFactory;

    protected $fillable = [
        'clinic_name',
        'logo',
        'address',
        'phone',
        'email',
        'subtitle',
        'receipt_prefix',
        'currency',
        'receipt_footer',
        'sms_balance',
        'whatsapp_balance',
        'notifications',
        'whatsapp_expires_at',
        'activity_log_enabled'
    ];

    protected $casts = [
        'notifications' => 'array',
        'whatsapp_expires_at' => 'datetime',
        'activity_log_enabled' => 'boolean',
    ];

    public static function getSettings()
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'clinic_name' => null,
                'address' => null,
                'phone' => null,
                'email' => null,
                'subtitle' => null,
                'receipt_prefix' => 'REC-',
                'currency' => 'USD',
                'receipt_footer' => 'Thank you for choosing our facility for your healthcare needs.',
                'activity_log_enabled' => false,
                'notifications' => [
                    'appointments_created' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                    'appointments_upcoming' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                    'patient_created' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                    'staff_created' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                    'auth_forgot_password' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                    'auth_reset_password' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                    'billing' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                    'payment_success' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                    'marketing' => ['email' => true, 'sms' => false, 'whatsapp' => false],
                ]
            ]
        );
    }

    public function isWhatsappActive()
    {
        if ($this->whatsapp_balance <= 0) {
            return false;
        }

        if ($this->whatsapp_expires_at && $this->whatsapp_expires_at->isPast()) {
            $this->whatsapp_balance = 0;
            $this->save();
            return false;
        }

        return true;
    }

    public function deductSms()
    {
        $cost = (int) env('SMS_COST_PER_MESSAGE', 60);
        if ($this->sms_balance >= $cost) {
            $this->sms_balance -= $cost;
            $this->save();
            return true;
        }
        return false;
    }
}
