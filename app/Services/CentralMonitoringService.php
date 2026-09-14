<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CentralMonitoringService
{
    public function sendUsage(): void
    {
        // if (!config('monitoring.enabled')) {
        //     return;
        // }

        try {
            $usage = [
                'users' => \App\Models\User::count(),
                'workers' => \App\Models\Employee::count(),

                'active_users' => \App\Models\Employee::where(
                    'last_login_at',
                    '>=',
                    now()->subDays(30)
                )->count(),

                // 'active_users' => \App\Models\User::where(
                //     'last_login_at',
                //     '>=',
                //     now()->subDays(30)
                // )->count(),

                'patients' => \App\Models\Patient::count(),

                'appointments' => \App\Models\Appointment::count(),

                'invoices' => \App\Models\Invoice::count(),

                'payments' => \App\Models\PaymentTransaction::count(),

                'lab_orders' => \App\Models\LaboratoryOrder::count(),

                'sms_sent' => 0, // replace with your actual SMS model/count
            ];

            Http::timeout(10)
                ->withToken(config('monitoring.token'))
                ->withHeaders([
                    'X-Client-ID' => config('monitoring.client_id'),
                ])
                ->post(
                    rtrim(config('monitoring.url'), '/') . '/monitoring/usage',
                    $usage
                );
        } catch (\Throwable $e) {
            Log::warning('Central monitoring usage failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}