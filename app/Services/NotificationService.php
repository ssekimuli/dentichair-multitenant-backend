<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send SMS using EgoSMS API
     *
     * @param string $phone
     * @param string $message
     * @return bool
     */
    public static function sendSms($phone, $message)
    {
        $clientId = env('SMS_CLIENT_ID');

        if (!$clientId) {
            Log::warning("SMS_CLIENT_ID missing. Simulating SMS to {$phone}");
            \App\Models\ActivityLog::log('send_sms', "Simulated sending SMS to {$phone}", ['phone' => $phone]);
            return true;
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
                return true;
            } else {
                Log::error("SMS sending failed: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("SMS exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send WhatsApp using WAAPI
     *
     * @param string $phone
     * @param string $message
     * @return bool
     */
    public static function sendWhatsApp($phone, $message)
    {
        $clientId = env('SMS_CLIENT_ID');

        if (!$clientId) {
            Log::warning("SMS_CLIENT_ID missing. Simulating WhatsApp to {$phone}");
            \App\Models\ActivityLog::log('send_whatsapp', "Simulated sending WhatsApp to {$phone}", ['phone' => $phone]);
            return true;
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
                return true;
            } else {
                Log::error("WhatsApp sending failed: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send WhatsApp Media using WAAPI
     *
     * @param string $phone
     * @param string $mediaUrl
     * @param string $fileName
     * @param string $caption
     * @return bool
     */
    public static function sendMediaWhatsApp($phone, $mediaUrl, $fileName, $caption = '')
    {
        $clientId = env('SMS_CLIENT_ID');

        if (!$clientId) {
            Log::warning("SMS_CLIENT_ID missing. Simulating WhatsApp media to {$phone}: {$mediaUrl}");
            \App\Models\ActivityLog::log('send_whatsapp', "Simulated sending WhatsApp media to {$phone}", ['phone' => $phone, 'media' => $fileName]);
            return true;
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
                return true;
            } else {
                Log::error("WhatsApp media sending failed: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp media exception: " . $e->getMessage());
            return false;
        }
    }
}
