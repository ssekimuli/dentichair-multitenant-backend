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
        // Find patients whose last visit was exactly 6 months ago today
        $targetDate = now()->subMonths(6)->toDateString();

        $patients = Patient::whereDate('last_visit', $targetDate)
            ->whereNotNull('email')
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
            try {
                Mail::send('emails.routine-checkup', [
                    'patientName' => $patient->first_name . ' ' . $patient->last_name,
                    'lastVisit' => $patient->last_visit->format('M d, Y'),
                ], function ($message) use ($patient) {
                    $message->to($patient->email)
                            ->subject('Time for your 6-month dental checkup! 🦷');
                });

                $sent++;
            } catch (\Exception $e) {
                logger()->error("Failed to send checkup reminder to {$patient->email}: " . $e->getMessage());
                $this->error("Failed to send reminder to {$patient->email}");
            }
        }

        $this->info("Sent {$sent} routine checkup reminder(s).");

        return Command::SUCCESS;
    }
}
