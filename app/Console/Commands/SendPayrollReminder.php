<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPayrollReminder extends Command
{
    protected $signature = 'payroll:send-reminder';
    protected $description = 'Send payroll processing reminder to admins and HR on the 1st of each month';

    public function handle()
    {
        if (now()->day !== 1) {
            $this->info('Today is not the 1st of the month. Skipping payroll reminder.');
            return Command::SUCCESS;
        }

        $month = now()->format('F Y');

        $recipients = Employee::where(function ($query) {
                $query->where('role', 'Super Admin')
                      ->orWhere('role', 'like', '%HR%');
            })
            ->whereNotNull('email')
            ->get();

        if ($recipients->isEmpty()) {
            $this->warn('No admin or HR employees found to notify.');
            return Command::SUCCESS;
        }

        $sent = 0;

        foreach ($recipients as $employee) {
            try {
                Mail::send('emails.payroll-reminder', [
                    'recipientName' => $employee->name,
                    'month' => $month,
                ], function ($message) use ($employee, $month) {
                    $message->to($employee->email)
                            ->subject('Payroll Processing Reminder - ' . $month);
                });

                $sent++;
            } catch (\Exception $e) {
                logger()->error("Failed to send payroll reminder to {$employee->email}: " . $e->getMessage());
                $this->error("Failed to send reminder to {$employee->email}");
            }
        }

        $this->info("Sent payroll reminder to {$sent} recipient(s) for {$month}.");

        return Command::SUCCESS;
    }
}
