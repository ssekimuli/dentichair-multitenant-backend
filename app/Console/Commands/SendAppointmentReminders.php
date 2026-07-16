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
        $tomorrow = now()->addDay()->toDateString();

        $appointments = Appointment::with('patient')
            ->where('status', 'Scheduled')
            ->whereDate('date', $tomorrow)
            ->get();

        $sent = 0;

        foreach ($appointments as $appointment) {
            if (!$appointment->patient || !$appointment->patient->email) {
                continue;
            }

            try {
                Mail::to($appointment->patient->email)->send(new \App\Mail\GenericMarkdownMail('emails.appointment-reminder', [
                    'patientName' => $appointment->patient->first_name . ' ' . $appointment->patient->last_name,
                    'doctorName' => $appointment->doctor_name,
                    'treatment' => $appointment->treatment,
                    'date' => $appointment->date->toDateString(),
                    'time' => $appointment->time,
                    'tokenNumber' => $appointment->token_number,
                ], 'Appointment Reminder - Dental Management System'));

                $sent++;
            } catch (\Exception $e) {
                logger()->error("Failed to send appointment reminder to {$appointment->patient->email}: " . $e->getMessage());
                $this->error("Failed to send reminder to {$appointment->patient->email}");
            }
        }

        $this->info("Sent {$sent} appointment reminder(s) for {$tomorrow}.");

        return Command::SUCCESS;
    }
}
