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
                'X-Client-domain' => env('APP_URL'),
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
        $clientId = env('SMS_CLIENT_ID');

        if (!$clientId) {

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
                'X-Client-domain' => env('APP_URL'),
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
                'X-Client-domain' => env('APP_URL'),
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
        $clientId = env('SMS_CLIENT_ID');

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
                'X-Client-domain' => env('APP_URL'),
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
}
