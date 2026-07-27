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
        $invoices = Invoice::with('patient')
            ->where('status', 'Pending')
            ->where('due_date', '<', now()->startOfDay())
            ->get();

        $patientDetails = [];
        foreach ($invoices as $invoice) {
            $invoice->update(['status' => 'Overdue']);
            $patientDetails[] = [
                'name' => $invoice->patient_name ?? ($invoice->patient ? $invoice->patient->first_name . ' ' . $invoice->patient->last_name : 'Unknown Patient'),
                'phone' => $invoice->patient->phone ?? null,
                'email' => $invoice->patient->email ?? null,
            ];
        }

        $count = count($patientDetails);
        $this->info("Marked {$count} invoice(s) as overdue.");

        \App\Models\ActivityLog::log(
            'cron_job',
            "Marked {$count} invoice(s) as overdue.",
            ['patients' => $patientDetails]
        );

        return Command::SUCCESS;
    }
}
