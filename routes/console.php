<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dental Management System Cron Jobs
Schedule::command('app:tenants:run invoices:mark-overdue')->daily();
Schedule::command('app:tenants:run leave:expire-pending')->daily();
Schedule::command('app:tenants:run inventory:check-low-stock')->dailyAt('08:00');
Schedule::command('app:tenants:run payroll:send-reminder')->dailyAt('08:00');
Schedule::command('app:tenants:run appointments:send-reminders')->dailyAt('18:00');
Schedule::command('app:tenants:run patients:routine-checkup')->dailyAt('09:00');
Schedule::command('app:tenants:run patients:send-birthday-wishes')->dailyAt('09:00');
Schedule::command('app:tenants:run app:auto-schedule-next-visits')->daily();
Schedule::command('app:tenants:run monitoring:usage')
    ->everyTenMinutes()
    // ->dailyAt('18:00')
    ->withoutOverlapping();
