<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AddSmsBalance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:add-balance {amount=1200}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add balance to SMS settings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $amount = (int) $this->argument('amount');
        $settings = \App\Models\GlobalSetting::getSettings();
        
        $settings->sms_balance += $amount;
        $settings->save();

        $this->info("Successfully added {$amount} to SMS balance. New balance: {$settings->sms_balance}");
    }
}
