<?php

namespace App\Console\Commands;

use App\Models\Patient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendRoutineCheckupReminders extends Command
{
    protected $signature = 'patients:routine-checkup';
    protected $description = 'Send a 6-month routine checkup reminder to patients based on their last visit';

    public function handle()
    {
        $settings = \App\Models\GlobalSetting::firstOrCreate(['id' => 1]);
        $clinicName = $settings->clinic_name ?: 'Dental Clinic';

        // Find patients whose last visit was exactly 6 months ago today
        $targetDate = now()->subMonths(6)->toDateString();

        $patients = Patient::whereDate('last_visit', $targetDate)
            ->where(function($q) {
                $q->whereNotNull('email')->orWhereNotNull('phone');
            })
            ->whereDoesntHave('appointments', function ($query) {
                // Ensure they don't already have a future appointment scheduled
                $query->whereDate('date', '>=', now()->toDateString())
                      ->whereIn('status', ['Scheduled', 'Confirmed']);
            })
            ->get();

        if ($patients->isEmpty()) {
            $this->info("No routine checkup reminders to send today for visits on {$targetDate}.");
            return Command::SUCCESS;
        }

        $sent = 0;

        foreach ($patients as $patient) {
            $msg = "Hello {$patient->first_name}, it's been 6 months since your last visit to {$clinicName}. It's time for your routine dental checkup! Please contact us to schedule an appointment.";

            // Email
            if ($settings->send_email_notifications && $patient->email) {
                try {
                    Mail::to($patient->email)->send(new \App\Mail\GenericMarkdownMail('emails.routine-checkup', [
                        'patientName' => $patient->first_name . ' ' . $patient->last_name,
                        'lastVisit' => $patient->last_visit->format('M d, Y'),
                        'clinicName' => $clinicName
                    ], "Time for your 6-month dental checkup! - {$clinicName}"));
                } catch (\Exception $e) {
                    logger()->error("Failed to send checkup reminder email to {$patient->email}: " . $e->getMessage());
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
            
            $sent++;
        }

        $this->info("Sent {$sent} routine checkup reminder(s).");

        return Command::SUCCESS;
    }
}
