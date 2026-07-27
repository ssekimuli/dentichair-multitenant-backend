<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $clientId = env('SMS_CLIENT_ID');

        if (!$clientId) {
            Log::warning("SMS_CLIENT_ID missing. Simulating SMS to {$phone}");
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
            ])->post('https://onboarddental.poslycloud.com/api/send-sms', [
                'phone' => $phone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                Log::info("SMS successfully sent to {$phone}");
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
                    'message' => 'EgoSMS API rejected the request',
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            Log::error("SMS exception: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An exception occurred while sending SMS',
                'error' => $e->getMessage()
            ];
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
        $clientId = env('SMS_CLIENT_ID');

        if (!$clientId) {
            Log::warning("SMS_CLIENT_ID missing. Simulating WhatsApp to {$phone}");
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
            ])->post('https://onboarddental.poslycloud.com/api/send-whatsapp', [
                'phone' => $phone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                Log::info("WhatsApp successfully sent to {$phone}");
                \App\Models\ActivityLog::log('send_whatsapp', "Successfully sent WhatsApp to {$phone}", ['phone' => $phone]);
                return [
                    'success' => true,
                    'message' => 'WhatsApp message sent successfully',
                    'data' => $response->json()
                ];
            } else {
                Log::error("WhatsApp sending failed: " . $response->body());
                return [
                    'success' => false,
                    'message' => 'WAAPI rejected the request',
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp exception: " . $e->getMessage());
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
        $clientId = env('SMS_CLIENT_ID');

        if (!$clientId) {
            Log::warning("SMS_CLIENT_ID missing. Simulating WhatsApp media to {$phone}: {$mediaUrl}");
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
            ])->post('https://onboarddental.poslycloud.com/api/send-whatsapp-media', [
                'phone' => $phone,
                'media_url' => $mediaUrl,
                'file_name' => $fileName,
                'caption' => $caption,
            ]);

            if ($response->successful()) {
                Log::info("WhatsApp media successfully sent to {$phone}");
                \App\Models\ActivityLog::log('send_whatsapp', "Successfully sent WhatsApp media to {$phone}", ['phone' => $phone, 'media' => $fileName]);
                return [
                    'success' => true,
                    'message' => 'WhatsApp media sent successfully',
                    'data' => $response->json()
                ];
            } else {
                Log::error("WhatsApp media sending failed: " . $response->body());
                return [
                    'success' => false,
                    'message' => 'WAAPI rejected the request',
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp media exception: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An exception occurred while sending WhatsApp media',
                'error' => $e->getMessage()
            ];
        }
    }
}
