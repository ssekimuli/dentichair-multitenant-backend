<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\InventoryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CheckLowStockInventory extends Command
{
    protected $signature = 'inventory:check-low-stock';
    protected $description = 'Check for low stock and out-of-stock inventory items and notify admins';

    public function handle()
    {
        $outOfStockItems = InventoryItem::where('stock', 0)->get();
        $lowStockItems = InventoryItem::where('stock', '>', 0)
            ->whereColumn('stock', '<=', 'reorder_level')
            ->get();

        if ($outOfStockItems->isEmpty() && $lowStockItems->isEmpty()) {
            $this->info('All inventory levels are healthy. No alerts needed.');
            return Command::SUCCESS;
        }

        $this->info("Found {$outOfStockItems->count()} out-of-stock and {$lowStockItems->count()} low-stock item(s).");

        $admins = Employee::where('role', 'Super Admin')
            ->whereNotNull('email')
            ->get();

        if ($admins->isEmpty()) {
            $this->warn('No Super Admin employees found to notify.');
            return Command::SUCCESS;
        }

        $sent = 0;

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->send(new \App\Mail\GenericMarkdownMail('emails.low-stock-alert', [
                    'lowStockItems' => $lowStockItems,
                    'outOfStockItems' => $outOfStockItems,
                ], 'Inventory Alert - Low Stock Items'));

                $sent++;
            } catch (\Exception $e) {
                logger()->error("Failed to send low stock alert to {$admin->email}: " . $e->getMessage());
                $this->error("Failed to send alert to {$admin->email}");
            }
        }

        $this->info("Sent low stock alerts to {$sent} admin(s).");

        return Command::SUCCESS;
    }
}
