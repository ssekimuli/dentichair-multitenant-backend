<?php

namespace App\Http\Controllers;

use App\Models\GlobalSetting;
use Illuminate\Http\Request;

class BranchSettingController extends Controller
{
    public function getSettings(Request $request)
    {
        $globalSetting = GlobalSetting::getSettings();
        $user = $request->user();

        $branch = null;
        if ($user && $user->branch_id) {
            $branch = $user->branch;
        }

        return response()->json([
            'clinic_name' => $globalSetting->clinic_name,
            'address' => $globalSetting->address,
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'currency' => $branch->currency,
            ] : null,
            'sms_balance' => $globalSetting->sms_balance,
            'whatsapp_balance' => $globalSetting->whatsapp_balance,
            'whatsapp_expires_at' => $globalSetting->whatsapp_expires_at,
            'activity_log_enabled' => $globalSetting->activity_log_enabled,
            'notifications' => $globalSetting->notifications,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'clinic_name' => 'nullable|string|max:255',
            'activity_log_enabled' => 'nullable|boolean',
            'notifications' => 'required|array',
            'notifications.*.email' => 'boolean',
            'notifications.*.sms' => 'boolean',
            'notifications.*.whatsapp' => 'boolean',
        ]);

        $globalSetting = GlobalSetting::getSettings();

        if (array_key_exists('clinic_name', $validated)) {
            $globalSetting->clinic_name = $validated['clinic_name'];
        }
        if (array_key_exists('activity_log_enabled', $validated)) {
            $globalSetting->activity_log_enabled = $validated['activity_log_enabled'];
        }

        $globalSetting->notifications = $validated['notifications'];
        $globalSetting->save();

        return response()->json($globalSetting);
    }

    public function topUpCredits(Request $request)
    {
        $request->validate([
            'type' => 'required|in:sms,whatsapp',
            'amount' => 'required|integer|min:1'
        ]);

        $globalSetting = GlobalSetting::getSettings();

        // Log the top-up
        \App\Models\TopUp::create([
            'type' => $request->type,
            'amount' => $request->amount,
            'date_paid' => now(),
        ]);

        if ($request->type === 'sms') {
            $globalSetting->sms_balance += $request->amount;
        } else if ($request->type === 'whatsapp') {
            $globalSetting->whatsapp_balance += $request->amount;
            
            // If it's already expired or null, start from now, else extend
            if (is_null($globalSetting->whatsapp_expires_at) || $globalSetting->whatsapp_expires_at->isPast()) {
                $globalSetting->whatsapp_expires_at = now()->addDays(30);
            } else {
                $globalSetting->whatsapp_expires_at = $globalSetting->whatsapp_expires_at->addDays(30);
            }
        }

        $globalSetting->save();

        return response()->json($globalSetting);
    }
}
