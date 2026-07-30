<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Send SMS
     */
    public function sendSms(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string',
                'message' => 'required|string',
            ]);

            $response = NotificationService::sendSms($request->phone, $request->message);

            return response()->json($response, $response['success'] ? 200 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    public function topUpSms(Request $request)
    {
        try {
            $request->validate([
                'phone' => [
                    'required',
                    'string',
                    'regex:/^\+[1-9]\d{7,14}$/',
                ],
                'amount' => 'required|numeric',
                'category' => 'required|string',
                'provider' => 'required|string',
            ]);

            $response = NotificationService::topUpSms($request->phone, $request->amount, $request->category,$request->provider);

            return response()->json($response, $response['success'] ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    public function verifyWebhook(Request $request){
        try{
             $response = NotificationService::verifyWebhook($request->reference);

            return response()->json($response, $response['success'] ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateTopUpBalance(Request $request){
        try{

            \Log::info("Top up  ", $request->all());

            $request->validate([
                'type' => 'required|in:sms,whatsapp',
                'amount' => 'required|numeric',
                'expiredate' => 'required_if:type,whatsapp|date',
            ]);

            $globalSetting = \App\Models\GlobalSetting::getSettings();

            if ($request->type === 'sms') {
                $globalSetting->sms_balance += $request->amount;
            } else if ($request->type === 'whatsapp') {
                $globalSetting->whatsapp_balance += $request->amount;
                $globalSetting->whatsapp_expires_at = \Carbon\Carbon::parse($request->expiredate);
            }
            $globalSetting->save();

            return response()->json([
                'success' => true,
                'message' => ucfirst($request->type) . ' balance updated successfully.',
                'data' => $globalSetting
            ], 200);

        } catch (\Exception $e) {

            \Log::info("Top up Exception to {$e}");

            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Send WhatsApp Message
     */
    public function sendWhatsApp(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string',
                'message' => 'required|string',
            ]);

            $response = NotificationService::sendWhatsApp($request->phone, $request->message);

            return response()->json($response, $response['success'] ? 200 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    public function sendWhatsAppMedia(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string',
                'media_url' => 'required_without:file|nullable|url',
                'file' => 'required_without:media_url|nullable|file|max:20480',
                'file_name' => 'required_without:file|nullable|string',
                'caption' => 'nullable|string',
            ]);

            $mediaUrl = $request->media_url;
            $fileName = $request->file_name;

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileName = $fileName ?? $file->getClientOriginalName();
                
                // Upload file to Cloudflare R2
                $r2Service = app(\App\Services\CloudflareR2Service::class);
                $mediaUrl = $r2Service->upload($file, 'whatsapp-media');
            }

            $response = NotificationService::sendMediaWhatsApp(
                $request->phone,
                $mediaUrl,
                $fileName,
                $request->caption ?? ''
            );

            return response()->json($response, $response['success'] ? 200 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
}
