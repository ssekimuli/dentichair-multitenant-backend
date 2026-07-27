<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\NotificationService;

class NotificationController extends Controller
{
    /**
     * Send SMS
     */
    public function sendSms(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
        ]);

        $response = NotificationService::sendSms($request->phone, $request->message);

        return response()->json($response, $response['success'] ? 200 : 500);
    }

    /**
     * Send WhatsApp Message
     */
    public function sendWhatsApp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
        ]);

        $response = NotificationService::sendWhatsApp($request->phone, $request->message);

        return response()->json($response, $response['success'] ? 200 : 500);
    }

    /**
     * Send WhatsApp Media
     */
    public function sendWhatsAppMedia(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'media_url' => 'required|url',
            'file_name' => 'required|string',
            'caption' => 'nullable|string',
        ]);

        $response = NotificationService::sendMediaWhatsApp(
            $request->phone,
            $request->media_url,
            $request->file_name,
            $request->caption ?? ''
        );

        return response()->json($response, $response['success'] ? 200 : 500);
    }
}
