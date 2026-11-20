<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\TenantContext;

class NotificationService
{
    /**
     * Send SMS
     *
     * @param string $phone
     * @param string $message
     * @return array
     */
    public static function sendSms($phone, $message)
    {
        $tenantContext = app(TenantContext::class);
        $tenant = $tenantContext->current();
        $clientId = $tenantContext->clientId();

        if (!$clientId) {
            if ($tenant) {
                Log::warning('SMS was not sent because the tenant has no SMS client ID.', [
                    'tenant_id' => $tenant->getTenantKey(),
                ]);

                return [
                    'success' => false,
                    'message' => 'SMS client ID is not configured for this tenant',
                ];
            }

            \App\Models\ActivityLog::log('send_sms', "Simulated sending SMS to {$phone}", ['phone' => $phone]);
            return [
                'success' => true,
                'message' => 'Simulated sending SMS (credentials missing)',
                'data' => null
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-domain' => app(TenantContext::class)->applicationUrl(),
            ])->post('https://onboarddental.poslycloud.com/api/send-sms', [
                'phone' => $phone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                \App\Models\ActivityLog::log('send_sms', "Successfully sent SMS to {$phone}", ['phone' => $phone]);
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'data' => $response->json()
                ];
            } else {
                Log::error("SMS sending failed: " . $response->body());
                return [
                    'success' => false,
                    'message' => 'SMS API rejected the request',
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'An exception occurred while sending SMS',
                'error' => $e->getMessage()
            ];
        }
    }
    public static function topUpSms($phone, $amount, $category,$provider)
    {
        $tenantContext = app(TenantContext::class);
        $tenant = $tenantContext->current();
        $clientId = $tenantContext->clientId();

        if (!$clientId) {
            if ($tenant) {
                Log::warning('SMS top-up was not requested because the tenant has no SMS client ID.', [
                    'tenant_id' => $tenant->getTenantKey(),
                ]);

                return [
                    'success' => false,
                    'message' => 'SMS client ID is not configured for this tenant',
                ];
            }

            \App\Models\ActivityLog::log('top_up_sms', "Fail Top Up SMS to {$phone} amount {$amount}", ['phone' => $phone]);
            return [
                'success' => true,
                'message' => 'Fail Top Up SMS (credentials missing)',
                'data' => null
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-domain' => app(TenantContext::class)->applicationUrl(),
            ])->post('https://onboarddental.poslycloud.com/api/top-up-sms', [
                'phone' => $phone,
                'amount' => $amount,
                'category'=>$category,
                'provider'=>$provider
            ]);

            if ($response->successful()) {
                \App\Models\ActivityLog::log('top_up_sms', "Successfully Top Up SMS to {$phone}", ['phone' => $phone]);
                return [
                    'success' => true,
                    'message' => 'Top Up SMS successfully',
                    'data' => $response->json()
                ];
            } else {

                return [
                    'success' => false,
                    'message' => 'Top Up SMS API rejected the request',
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'An exception occurred while Top Up SMS',
                'error' => $e->getMessage()
            ];
        }
    }
    public static function verifyWebhook($reference)
    {
        $clientId = app(TenantContext::class)->clientId();

        if (!$clientId) {
            \App\Models\ActivityLog::log('verify_payment', "Fail to verify {$reference}", ['reference' => $reference]);
            return [
                'success' => false,
                'message' => 'Fail to verify',
                'data' => null
            ];
        }

        try {
           $response = Http::withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-domain' => app(TenantContext::class)->applicationUrl(),
            ])->post('https://onboarddental.poslycloud.com/api/verify-transaction', [
                'reference' => $reference
            ]);

            if ($response->successful()) {
                if (!$response['success']) {
                    \App\Models\ActivityLog::log('verify_payment', "Webhook verify failed", [
                        'reference' => $reference,
                        'error' => $response['error'] ?? 'Verification failed',
                    ]);
                    return [
                        'success' => false,
                        'error'   => $response['error'] ?? 'Verification failed',
                    ];
                }


                $verifiedData = $response['data'];

                self::processPaymentStatus($verifiedData);

                return [
                    'success' => true,
                    'data'    => $verifiedData,
                ];
            } else {
                \App\Models\ActivityLog::log('verify_payment', "Webhook verify request failed", [
                    'reference' => $reference,
                    'error' => $response->body()
                ]);
                return [
                    'success' => false,
                    'error'   => 'Verification request failed',
                ];
            }
        } catch (\Exception $e) {
            \App\Models\ActivityLog::log('verify_payment', "Webhook verify exception", [
                'reference' => $reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'success' => false,
                'error'   => 'Verification error',
            ];
        }
    }

    private static function processPaymentStatus(array $data)
    {
        $status = strtolower($data['status'] ?? '');
       
        if (in_array($status, ['completed', 'success', 'successful'])) {
            \App\Models\ActivityLog::log('payment_status', "Payment completed", ['status' => $status, 'data' => $data]);
            // Update 
        } elseif ($status === 'failed') {
            \App\Models\ActivityLog::log('payment_status', "Payment failed", ['status' => $status, 'data' => $data]);
            return $data;
            // Update UserSubscription status
        } elseif ($status === 'pending') {
           \App\Models\ActivityLog::log('payment_status', "Payment pending", ['status' => $status, 'data' => $data]);
        } else {
           \App\Models\ActivityLog::log('payment_status', "Payment status unknown: {$status}", ['status' => $status, 'data' => $data]);
        }
    }

    /**
     * Send WhatsApp
     *
     * @param string $phone
     * @param string $message
     * @return array
     */
    public static function sendWhatsApp($phone, $message)
    {
        $clientId = app(TenantContext::class)->clientId();

        if (!$clientId) {
            \App\Models\ActivityLog::log('send_whatsapp', "Simulated sending WhatsApp to {$phone}", ['phone' => $phone]);
            return [
                'success' => true,
                'message' => 'Simulated sending WhatsApp (credentials missing)',
                'data' => null
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-domain' => app(TenantContext::class)->applicationUrl(),
            ])->post('https://onboarddental.poslycloud.com/api/send-whatsapp', [
                'phone' => $phone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                \App\Models\ActivityLog::log('send_whatsapp', "Successfully sent WhatsApp to {$phone}", ['phone' => $phone]);
                return [
                    'success' => true,
                    'message' => 'WhatsApp message sent successfully',
                    'data' => $response->json()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'WAAPI rejected the request',
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'An exception occurred while sending WhatsApp message',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send WhatsApp Media
     *
     * @param string $phone
     * @param string $mediaUrl
     * @param string $fileName
     * @param string $caption
     * @return array
     */
    public static function sendMediaWhatsApp($phone, $mediaUrl, $fileName, $caption = '')
    {
        $clientId = app(TenantContext::class)->clientId();

        if (!$clientId) {
            \App\Models\ActivityLog::log('send_whatsapp', "Simulated sending WhatsApp media to {$phone}", ['phone' => $phone, 'media' => $fileName]);
            return [
                'success' => true,
                'message' => 'Simulated sending WhatsApp media (credentials missing)',
                'data' => null
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-domain' => app(TenantContext::class)->applicationUrl(),
            ])->post('https://onboarddental.poslycloud.com/api/send-whatsapp-media', [
                'phone' => $phone,
                'media_url' => $mediaUrl,
                'file_name' => $fileName,
                'caption' => $caption,
            ]);

            if ($response->successful()) {
                \App\Models\ActivityLog::log('send_whatsapp', "Successfully sent WhatsApp media to {$phone}", ['phone' => $phone, 'media' => $fileName]);
                return [
                    'success' => true,
                    'message' => 'WhatsApp media sent successfully',
                    'data' => $response->json()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'WAAPI rejected the request',
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'An exception occurred while sending WhatsApp media',
                'error' => $e->getMessage()
            ];
        }
    }

    public static function getWelcomeMessage($businessName, $email, $password)
    {
        $loginUrl = app(TenantContext::class)->applicationUrl();
        $clinicName = app(TenantContext::class)->current()?->display_name ?: $businessName;

        $message = "**Welcome to {$clinicName}! 🦷**\n\n";
        $message .= "Dear **{$clinicName}**,\n\n";
        $message .= "Welcome to the DentiChair family! We're excited to have you onboard.\n\n";
        $message .= "DentiChair is designed to simplify the daily operations of your dental practice by helping you manage patients, appointments, treatment records, billing, and much more—all in one place.\n\n";
        $message .= "If you need any assistance getting started, our support team is always ready to help.\n\n";
        $message .= "Thank you for choosing DentiChair. We look forward to helping your clinic grow and operate more efficiently.\n\n";
        $message .= "**Login:** {$loginUrl}\n";
        $message .= "**Username:** {$email}\n";
        $message .= "**Temporary Password:** {$password}\n\n";
        $message .= "Best regards,\n";
        $message .= "**The {$clinicName} Team**";

        return $message;
    }
    public static function syncTopUpBalance(array $data)
    {
        $clientId = app(TenantContext::class)->clientId();
        if (!$clientId) {
            Log::warning("Cannot sync top up balance: SMS_CLIENT_ID is missing.");
            return;
        }

        try {
            Http::withHeaders([
                'X-Client-Id' => $clientId,
                'X-Client-domain' => app(TenantContext::class)->applicationUrl(),
            ])->timeout(5)->post('https://onboarddental.poslycloud.com/api/update-top-up-balance', $data);
        } catch (\Exception $e) {
            Log::error("Failed to send top up update to poslycloud: " . $e->getMessage());
        }
    }
}
