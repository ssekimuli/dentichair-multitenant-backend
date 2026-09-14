<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dental Management System Cron Jobs
Schedule::command('invoices:mark-overdue')->daily();
Schedule::command('leave:expire-pending')->daily();
Schedule::command('inventory:check-low-stock')->dailyAt('08:00');
Schedule::command('payroll:send-reminder')->dailyAt('08:00');
Schedule::command('appointments:send-reminders')->dailyAt('18:00');
Schedule::command('patients:routine-checkup')->dailyAt('09:00');
Schedule::command('patients:send-birthday-wishes')->dailyAt('09:00');
Schedule::command('app:auto-schedule-next-visits')->daily();
Schedule::command('monitoring:usage')
    ->everyTenMinutes()
    ->withoutOverlapping();
