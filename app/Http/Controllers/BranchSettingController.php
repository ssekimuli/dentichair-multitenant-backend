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

    /**
     * Get Facility Receipt Configuration
     * GET /api/settings/receipt
     */
    public function getReceiptSettings()
    {
        $globalSetting = GlobalSetting::getSettings();

        return response()->json([
            'clinic_name' => $globalSetting->clinic_name,
            'subtitle' => $globalSetting->subtitle,
            'address' => $globalSetting->address,
            'phone' => $globalSetting->phone,
            'email' => $globalSetting->email,
            'receipt_prefix' => $globalSetting->receipt_prefix ?? 'REC-',
            'currency' => $globalSetting->currency ?? 'USD',
            'receipt_footer' => $globalSetting->receipt_footer,
            'updated_at' => $globalSetting->updated_at,
        ]);
    }

    /**
     * Update Facility Receipt Configuration
     * PUT/POST /api/settings/receipt
     */
    public function updateReceiptSettings(Request $request)
    {
        $validated = $request->validate([
            'clinic_name' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'receipt_prefix' => 'nullable|string|max:20',
            'currency' => 'nullable|string|max:10',
            'receipt_footer' => 'nullable|string|max:1000',
        ]);

        $globalSetting = GlobalSetting::getSettings();

        foreach ([
            'clinic_name',
            'subtitle',
            'address',
            'phone',
            'email',
            'receipt_prefix',
            'currency',
            'receipt_footer'
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $globalSetting->{$field} = $validated[$field];
            }
        }

        $globalSetting->save();

        return response()->json([
            'message' => 'Facility receipt settings updated successfully',
            'data' => [
                'clinic_name' => $globalSetting->clinic_name,
                'subtitle' => $globalSetting->subtitle,
                'address' => $globalSetting->address,
                'phone' => $globalSetting->phone,
                'email' => $globalSetting->email,
                'receipt_prefix' => $globalSetting->receipt_prefix ?? 'REC-',
                'currency' => $globalSetting->currency ?? 'USD',
                'receipt_footer' => $globalSetting->receipt_footer,
                'updated_at' => $globalSetting->updated_at,
            ]
        ]);
    }
}
