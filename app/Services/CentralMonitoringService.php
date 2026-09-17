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
            $clientId = env('SMS_CLIENT_ID');

            $usage = [
                'users' => \App\Models\User::count(),
                'workers' => \App\Models\Employee::count(),

                'active_users' => \App\Models\Employee::where(
                    'last_login_at',
                    '>=',
                    now()->subDays(14)
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
                'recorded_at'=>now(),
            ];

            $response = Http::withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-domain' => env('APP_URL'),
            ])->post('https://onboarddental.poslycloud.com/api/monitoring/usage', $usage);

        } catch (\Exception $e) {
            Log::info($e->getMessage());
        }
    }
}
