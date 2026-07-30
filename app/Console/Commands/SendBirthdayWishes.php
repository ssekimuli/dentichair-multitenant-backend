<?php

namespace App\Console\Commands;

use App\Models\Patient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendBirthdayWishes extends Command
{
    protected $signature = 'patients:send-birthday-wishes';
    protected $description = 'Send birthday wishes to patients via email, SMS, and WhatsApp';

    public function handle()
    {
        $settings = \App\Models\GlobalSetting::firstOrCreate(['id' => 1]);
        $clinicName = $settings->clinic_name ?: 'Dental Clinic';

        $patients = Patient::whereMonth('date_of_birth', now()->month)
            ->whereDay('date_of_birth', now()->day)
            ->where(function($q) {
                $q->whereNotNull('email')->orWhereNotNull('phone');
            })
            ->get();

        if ($patients->isEmpty()) {
            $this->info("No birthdays today.");
            return Command::SUCCESS;
        }

        $sent = 0;
        $patientDetails = [];

        foreach ($patients as $patient) {
            $msg = "Happy Birthday {$patient->first_name}! 🎉 Wishing you a fantastic day and a wonderful year ahead. Best wishes from all of us at {$clinicName}!";

            // Email
            if ($settings->send_email_notifications && $patient->email) {
                try {
                    Mail::to($patient->email)->send(new \App\Mail\GenericMarkdownMail('emails.birthday-wish', [
                        'patientName' => $patient->first_name,
                        'clinicName' => $clinicName
                    ], "Happy Birthday! 🎉 - {$clinicName}"));
                } catch (\Exception $e) {
                    logger()->error("Failed to send birthday wish email to {$patient->email}: " . $e->getMessage());
                }
            }

            // SMS
            if ($settings->send_sms_notifications && $patient->phone && $settings->deductSms()) {
                \App\Services\NotificationService::sendSMS($patient->phone, $msg);
            }

            // WhatsApp
            if ($settings->send_whatsapp_notifications && $patient->phone && $settings->isWhatsappActive()) {
                \App\Services\NotificationService::sendWhatsApp($patient->phone, $msg);
            }

            $patientDetails[] = [
                'name' => $patient->first_name . ' ' . $patient->last_name,
                'phone' => $patient->phone,
                'email' => $patient->email,
            ];
            $sent++;
        }

        $this->info("Sent {$sent} birthday wish(es) today.");

        \App\Models\ActivityLog::log(
            'cron_job',
            "Sent {$sent} birthday wish(es) today.",
            ['patients' => $patientDetails]
        );

        return Command::SUCCESS;
    }
}
