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

            // Create appointment
            Appointment::create([
                'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                'patient_id' => $patient->id,
                'doctor_name' => $doctor->name,
                'doctor_id' => $doctor->id,
                'treatment' => 'Routine Checkup',
                'status' => 'Scheduled',
                'date' => $patient->next_visit,
                'time' => '09:00 AM',
                'branch_id' => $patient->branch_id,
                'token_number' => 'T' . strtoupper(Str::random(6)),
            ]);

            // Clear the next_visit since it has been converted to an appointment
            $patient->update(['next_visit' => null]);
            $count++;
        }

        $this->info("Successfully scheduled {$count} appointments.");
    }
}
