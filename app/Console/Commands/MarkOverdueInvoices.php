<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';
    protected $description = 'Mark pending invoices as overdue when their due date has passed';

    public function handle()
    {
        $count = Invoice::where('status', 'Pending')
            ->where('due_date', '<', now()->startOfDay())
            ->update(['status' => 'Overdue']);

        $this->info("Marked {$count} invoice(s) as overdue.");

        \App\Models\ActivityLog::log(
            'cron_job',
            "Marked {$count} invoice(s) as overdue.",
            ['command' => $this->signature]
        );

        return Command::SUCCESS;
    }
}
