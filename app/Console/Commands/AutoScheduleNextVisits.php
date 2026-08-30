<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Patient;
use App\Models\Employee;
use App\Models\Appointment;
use Illuminate\Support\Str;

class AutoScheduleNextVisits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:auto-schedule-next-visits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically schedule appointments for patients with next visit dates set';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting auto-scheduling of next visits...');

        $patients = Patient::whereNotNull('next_visit')->get();

        $count = 0;
        $patientDetails = [];
        foreach ($patients as $patient) {
            // Find an active dentist/doctor in the same branch
            $doctor = Employee::where('branch_id', $patient->branch_id)
                ->where('status', 'Active')
                ->first();

            // If no doctor in same branch, look for any active employee in system
            if (!$doctor) {
                $doctor = Employee::where('status', 'Active')->first();
            }

            if (!$doctor) {
                $this->error("No active doctor found for patient: {$patient->first_name} {$patient->last_name} (ID: {$patient->id}). Skipping.");
                continue;
            }

            // Check if appointment already exists for this date
            $exists = Appointment::where('patient_id', $patient->id)
                ->whereDate('date', $patient->next_visit)
                ->exists();

            if ($exists) {
                // Already created (e.g. by the next-visit API's model hook) - just clear the flag.
                $patient->update(['next_visit' => null]);
                continue;
            }

            // Create appointment
            Appointment::create([
                'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                'patient_id' => $patient->id,
                'doctor_name' => $doctor->name,
                'doctor_id' => $doctor->id,
                'treatment' => 'Routine Checkup',
                'status' => 'Scheduled',
                'date' => $patient->next_visit->toDateString(),
                'time' => '09:00 AM',
                'branch_id' => $patient->branch_id,
                'token_number' => 'T' . strtoupper(Str::random(6)),
            ]);

            $patientDetails[] = [
                'name' => $patient->first_name . ' ' . $patient->last_name,
                'phone' => $patient->phone,
                'email' => $patient->email,
            ];

            $patient->update(['next_visit' => null]);

            $count++;
        }

        $this->info("Successfully scheduled {$count} appointments.");

        \App\Models\ActivityLog::log(
            'cron_job',
            "Automatically scheduled {$count} appointments based on patients' next visit dates.",
            ['patients' => $patientDetails]
        );
    }
}
