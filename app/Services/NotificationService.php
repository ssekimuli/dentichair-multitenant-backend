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
        $username = env('EGOSMS_USERNAME');
        $password = env('EGOSMS_PASSWORD');
        $senderId = env('EGOSMS_SENDER_ID');

        if (!$username || !$password) {
            Log::warning("EgoSMS credentials missing. Simulating SMS to {$phone}");
            return true;
        }

        try {
            $response = Http::post('https://www.egosms.co/api/v1/json/', [
                'method' => 'SendSms',
                'userdata' => [
                    'username' => $username,
                    'password' => $password,
                ],
                'msgdata' => [
                    [
                        'number' => $phone,
                        'message' => $message,
                        'senderid' => $senderId,
                    ]
                ]
            ]);

            if ($response->successful()) {
                Log::info("EgoSMS successfully sent to {$phone}");
                return true;
            } else {
                Log::error("EgoSMS sending failed: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("EgoSMS exception: " . $e->getMessage());
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
        $instanceId = env('WAAPI_INSTANCE_ID');
        $token = env('WAAPI_TOKEN');

        if (!$instanceId || !$token) {
            Log::warning("WAAPI credentials missing. Simulating WhatsApp to {$phone}");
            return true;
        }

        // Format phone number to WhatsApp ID format (e.g., 256701234567@c.us)
        // Note: Assumes phone is already in international format without '+'
        $chatId = preg_replace('/[^0-9]/', '', $phone) . '@c.us';

        try {
            $url = "https://waapi.app/api/v1/instances/{$instanceId}/client/action/send-message";
            
            $response = Http::withToken($token)
                ->post($url, [
                    'chatId' => $chatId,
                    'message' => $message,
                ]);

            if ($response->successful()) {
                Log::info("WAAPI successfully sent to {$phone}");
                return true;
            } else {
                Log::error("WAAPI sending failed: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("WAAPI exception: " . $e->getMessage());
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
        $instanceId = env('WAAPI_INSTANCE_ID');
        $token = env('WAAPI_TOKEN');

        if (!$instanceId || !$token) {
            Log::warning("WAAPI credentials missing. Simulating WhatsApp media to {$phone}: {$mediaUrl}");
            return true;
        }

        $chatId = preg_replace('/[^0-9]/', '', $phone) . '@c.us';

        try {
            $url = "https://waapi.app/api/v1/instances/{$instanceId}/client/action/send-media";
            
            $response = Http::withToken($token)
                ->post($url, [
                    'chatId' => $chatId,
                    'mediaUrl' => $mediaUrl,
                    'mediaName' => $fileName,
                    'mediaCaption' => $caption,
                ]);

            if ($response->successful()) {
                Log::info("WAAPI media successfully sent to {$phone}");
                return true;
            } else {
                Log::error("WAAPI media sending failed: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("WAAPI media exception: " . $e->getMessage());
            return false;
        }
    }
}
