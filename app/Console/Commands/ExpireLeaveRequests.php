<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use Illuminate\Console\Command;

class ExpireLeaveRequests extends Command
{
    protected $signature = 'leave:expire-pending';
    protected $description = 'Auto-expire pending leave requests whose start date has passed';

    public function handle()
    {
        $leaveRequests = LeaveRequest::with('employee')
            ->where('status', 'Pending')
            ->where('start_date', '<', now()->startOfDay())
            ->get();

        $employeeDetails = [];
        foreach ($leaveRequests as $req) {
            $req->update(['status' => 'Expired']);
            if ($req->employee) {
                $employeeDetails[] = [
                    'name' => $req->employee->name,
                    'phone' => $req->employee->phone ?? null,
                    'email' => $req->employee->email,
                ];
            }
        }

        $count = count($employeeDetails);
        $this->info("Expired {$count} pending leave request(s).");

        \App\Models\ActivityLog::log(
            'cron_job',
            "Expired {$count} pending leave request(s).",
            ['employees' => $employeeDetails]
        );

        return Command::SUCCESS;
    }
}
