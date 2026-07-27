<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Send email reminders to patients with appointments scheduled for tomorrow';

    public function handle()
    {
        $settings = \App\Models\GlobalSetting::firstOrCreate(['id' => 1]);
        $clinicName = $settings->clinic_name ?: 'Dental Clinic';

        $tomorrow = now()->addDay()->toDateString();

        $appointments = Appointment::with('patient')
            ->where('status', 'Scheduled')
            ->whereDate('date', $tomorrow)
            ->get();

        $sent = 0;
        $patientDetails = [];

        foreach ($appointments as $appointment) {
            if (!$appointment->patient) {
                continue;
            }
            $patient = $appointment->patient;
            
            $msg = "Reminder: You have an appointment at {$clinicName} tomorrow (" . $appointment->date->format('M d, Y') . ") at {$appointment->time} with {$appointment->doctor_name}.";

            // Email
            if ($settings->send_email_notifications && $patient->email) {
                try {
                    Mail::to($patient->email)->send(new \App\Mail\GenericMarkdownMail('emails.appointment-reminder', [
                        'patientName' => $patient->first_name . ' ' . $patient->last_name,
                        'doctorName' => $appointment->doctor_name,
                        'treatment' => $appointment->treatment,
                        'date' => $appointment->date->format('M d, Y'),
                        'time' => $appointment->time,
                        'tokenNumber' => $appointment->token_number,
                        'clinicName' => $clinicName
                    ], "Appointment Reminder - {$clinicName}"));
                } catch (\Exception $e) {
                    logger()->error("Failed to send appointment reminder email to {$patient->email}: " . $e->getMessage());
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

        $this->info("Sent {$sent} appointment reminder(s) for {$tomorrow}.");

        \App\Models\ActivityLog::log(
            'cron_job',
            "Sent {$sent} appointment reminder(s) for {$tomorrow}.",
            ['patients' => $patientDetails]
        );

        return Command::SUCCESS;
    }
}
