<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceTreatment;
use App\Models\InvoiceOtherCharge;
use App\Models\PaymentTransaction;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public function run()
    {
        $invoices = [
            [
                'patient_id' => 1, 'patient_name' => 'John Doe', 'amount' => 250, 'paid_amount' => 100, 
                'status' => 'Pending', 'issue_date' => '2024-05-10', 'due_date' => '2024-06-10', 'branch_id' => 1,
                'treatments' => [['Filling for #16', 250]],
                'other_charges' => [],
                'transactions' => [['txn1', '2024-05-12', 100, 'Insurance']]
            ],
            [
                'patient_id' => 2, 'patient_name' => 'Jane Smith', 'amount' => 150, 'paid_amount' => 150, 
                'status' => 'Paid', 'issue_date' => '2024-04-22', 'due_date' => '2024-05-22', 'branch_id' => 2,
                'treatments' => [['Cleaning', 150]],
                'other_charges' => [],
                'transactions' => [['txn2', '2024-04-22', 150, 'Credit Card']]
            ],
            [
                'patient_id' => 4, 'patient_name' => 'Mike Williams', 'amount' => 300, 'paid_amount' => 300, 
                'status' => 'Paid', 'issue_date' => '2024-07-15', 'due_date' => '2024-08-15', 'branch_id' => 2,
                'treatments' => [['Filling', 300]],
                'other_charges' => [],
                'transactions' => [['txn3', '2024-07-15', 300, 'Cash']]
            ],
            [
                'patient_id' => 1, 'patient_name' => 'John Doe', 'amount' => 95, 'paid_amount' => 0, 
                'status' => 'Overdue', 'issue_date' => '2024-03-01', 'due_date' => '2024-04-01', 'branch_id' => 1,
                'treatments' => [['Regular Checkup', 75]],
                'other_charges' => [['Late Fee', 20]],
                'transactions' => []
            ],
        ];

        foreach ($invoices as $invoiceData) {
            try {
                // Create the invoice without specifying ID - let Laravel auto-generate
                $invoice = Invoice::create([
                    'patient_id' => $invoiceData['patient_id'],
                    'patient_name' => $invoiceData['patient_name'],
                    'amount' => $invoiceData['amount'],
                    'paid_amount' => $invoiceData['paid_amount'],
                    'status' => $invoiceData['status'],
                    'issue_date' => $invoiceData['issue_date'],
                    'due_date' => $invoiceData['due_date'],
                    'branch_id' => $invoiceData['branch_id'],
                    'payment_method' => !empty($invoiceData['transactions']) ? $invoiceData['transactions'][0][3] : null,
                ]);

                // Check if invoice was created successfully
                if (!$invoice->id) {
                    \Log::error("Invoice creation failed. Invoice object: " . json_encode($invoice));
                    continue;
                }

                \Log::info("Created invoice with ID: " . $invoice->id);

                // Create treatments
                foreach ($invoiceData['treatments'] as $treatment) {
                    InvoiceTreatment::create([
                        'invoice_id' => $invoice->id,
                        'procedure' => $treatment[0],
                        'cost' => $treatment[1],
                    ]);
                }

                // Create other charges
                foreach ($invoiceData['other_charges'] as $charge) {
                    InvoiceOtherCharge::create([
                        'invoice_id' => $invoice->id,
                        'description' => $charge[0],
                        'amount' => $charge[1],
                    ]);
                }

                // Create transactions
                foreach ($invoiceData['transactions'] as $transaction) {
                    PaymentTransaction::create([
                        'id' => $transaction[0],
                        'invoice_id' => $invoice->id,
                        'date' => $transaction[1],
                        'amount' => $transaction[2],
                        'method' => $transaction[3],
                    ]);
                }

            } catch (\Exception $e) {
                \Log::error("Error creating invoice: " . $e->getMessage());
                \Log::error("Invoice data: " . json_encode($invoiceData));
            }
        }
    }
}