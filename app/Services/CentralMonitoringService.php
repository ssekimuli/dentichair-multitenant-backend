<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\TenantContext;

class CentralMonitoringService
{
    public function sendUsage(): void
    {
        // if (!config('monitoring.enabled')) {
        //     return;
        // }

        try {
            $tenantContext = app(TenantContext::class);
            $clientId = $tenantContext->clientId();

            if (!$clientId) {
                Log::warning('Cannot send tenant usage without an SMS client ID.');
                return;
            }

            $usage = [
                'users' => \App\Models\User::count(),
                'workers' => \App\Models\Employee::count(),

                'active_users' => \App\Models\Employee::where(
                    'last_login_at',
                    '>=',
                    now()->subDays(14)
                )->count(),
                'last_login_today' => \App\Models\Employee::whereDate(
                    'last_login_at',
                    today()
                )->count(),

                // 'active_users' => \App\Models\User::where(
                //     'last_login_at',
                //     '>=',
                //     now()->subDays(30)
                // )->count(),

                'patients' => \App\Models\Patient::count(),
                'patients_created_today' => \App\Models\Patient::whereDate('created_at', today())->count(),

                'appointments' => \App\Models\Appointment::count(),
                'appointments_created_today' => \App\Models\Appointment::whereDate('created_at', today())->count(),

                'invoices' => \App\Models\Invoice::count(),
                'invoices_pending' => \App\Models\Invoice::unpaid()->count(),
                'invoices_today' => \App\Models\Invoice::whereDate('created_at', today())->count(),
                'invoices_paid' => \App\Models\Invoice::paid()->count(),
                
                'payments' => \App\Models\PaymentTransaction::count(),

                'lab_orders' => \App\Models\LaboratoryOrder::count(),
                'lab_orders_created_today' => \App\Models\LaboratoryOrder::whereDate('created_at', today())->count(),

                'sms_sent' => 0, // replace with your actual SMS model/count
                'recorded_at' => now(),
            ];

            $response = Http::withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-domain' => $tenantContext->applicationUrl(),
            ])->post('https://onboarddental.poslycloud.com/api/monitoring/usage', $usage);

        } catch (\Exception $e) {
            Log::info($e->getMessage());
        }
    }
}
