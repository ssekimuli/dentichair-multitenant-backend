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
        $count = LeaveRequest::where('status', 'Pending')
            ->where('start_date', '<', now()->startOfDay())
            ->update(['status' => 'Expired']);

        $this->info("Expired {$count} pending leave request(s).");

        \App\Models\ActivityLog::log(
            'cron_job',
            "Expired {$count} pending leave request(s).",
            ['command' => $this->signature]
        );

        return Command::SUCCESS;
    }
}
