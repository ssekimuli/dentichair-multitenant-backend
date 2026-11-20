<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GlobalSetting;
use App\Models\TopUp;
use App\Services\CloudflareR2Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BranchSettingController extends Controller
{
    /**
     * Get Branch and Global Settings
     * GET /api/settings/branch
     */
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
            'logo' => $globalSetting->logo,
            'logo_url' => $globalSetting->logo,
            'address' => $globalSetting->address,
            'qr_code_info_data' => $globalSetting->qr_code_info_data,
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'currency' => $branch->currency,
                'logo' => $branch->logo,
                'qr_code_info_data' => $branch->qr_code_info_data,
            ] : null,
            'sms_balance' => $globalSetting->sms_balance,
            'whatsapp_balance' => $globalSetting->whatsapp_balance,
            'whatsapp_expires_at' => $globalSetting->whatsapp_expires_at,
            'activity_log_enabled' => $globalSetting->activity_log_enabled,
            'notifications' => $globalSetting->notifications,
        ]);
    }

    /**
     * Update Global Settings
     * POST /api/settings/branch
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'clinic_name' => 'nullable|string|max:255',
            'logo' => 'nullable|string|max:1000',
            'qr_code_info_data' => 'nullable|string',
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
        if (array_key_exists('logo', $validated)) {
            $globalSetting->logo = $validated['logo'];
        }
        if (array_key_exists('qr_code_info_data', $validated)) {
            $globalSetting->qr_code_info_data = $validated['qr_code_info_data'];
        }
        if (array_key_exists('activity_log_enabled', $validated)) {
            $globalSetting->activity_log_enabled = $validated['activity_log_enabled'];
        }

        $globalSetting->notifications = $validated['notifications'];
        $globalSetting->save();

        return response()->json($globalSetting);
    }

    /**
     * Top Up SMS or WhatsApp Credits
     * POST /api/settings/branch/top-up
     */
    public function topUpCredits(Request $request)
    {
        $request->validate([
            'type' => 'required|in:sms,whatsapp',
            'amount' => 'required|integer|min:1'
        ]);

        $globalSetting = GlobalSetting::getSettings();

        // Log the top-up
        TopUp::create([
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
            'logo' => $globalSetting->logo,
            'logo_url' => $globalSetting->logo,
            'subtitle' => $globalSetting->subtitle,
            'address' => $globalSetting->address,
            'phone' => $globalSetting->phone,
            'email' => $globalSetting->email,
            'receipt_prefix' => $globalSetting->receipt_prefix ?? 'REC-',
            'currency' => $globalSetting->currency ?? 'USD',
            'receipt_footer' => $globalSetting->receipt_footer,
            'qr_code_info_data' => $globalSetting->qr_code_info_data,
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
            'logo' => 'nullable|string|max:1000',
            'subtitle' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'receipt_prefix' => 'nullable|string|max:20',
            'currency' => 'nullable|string|max:10',
            'receipt_footer' => 'nullable|string|max:1000',
            'qr_code_info_data' => 'nullable|string',
        ]);

        $globalSetting = GlobalSetting::getSettings();

        foreach ([
            'clinic_name',
            'logo',
            'subtitle',
            'address',
            'phone',
            'email',
            'receipt_prefix',
            'currency',
            'receipt_footer',
            'qr_code_info_data',
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
                'logo' => $globalSetting->logo,
                'logo_url' => $globalSetting->logo,
                'subtitle' => $globalSetting->subtitle,
                'address' => $globalSetting->address,
                'phone' => $globalSetting->phone,
                'email' => $globalSetting->email,
                'receipt_prefix' => $globalSetting->receipt_prefix ?? 'REC-',
                'currency' => $globalSetting->currency ?? 'USD',
                'receipt_footer' => $globalSetting->receipt_footer,
                'qr_code_info_data' => $globalSetting->qr_code_info_data,
                'updated_at' => $globalSetting->updated_at,
            ]
        ]);
    }

    /**
     * Upload Clinic/Facility Logo Image
     * POST /api/settings/logo or POST /api/upload/logo
     *
     * Accepts multipart file with name 'logo', 'image', or 'file' (max 10MB)
     * Optional 'branch_id' to also update a specific branch's logo.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            'image' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            'file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $file = $request->file('logo') ?? $request->file('image') ?? $request->file('file');

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide an image file using field name "logo", "image", or "file".'
            ], 422);
        }

        // Upload using Cloudflare R2 with automatic fallback to public disk
        $url = null;
        try {
            $r2Service = app(CloudflareR2Service::class);
            $url = $r2Service->upload($file, 'logos');
        } catch (\Throwable $e) {
            Log::warning('R2 logo upload failed, falling back to local public storage: ' . $e->getMessage());
            $path = $file->store(app(\App\Services\TenantContext::class)->storagePath('logos'), 'public');
            $url = asset('storage/' . $path);
        }

        // 1. Update GlobalSetting logo
        $globalSetting = GlobalSetting::getSettings();
        $globalSetting->logo = $url;
        $globalSetting->save();

        // 2. Update Business record logo if exists
        try {
            $business = Business::first();
            if ($business) {
                $business->logo = $url;
                $business->save();
            }
        } catch (\Throwable $e) {
            // Ignore if business table is not present
        }

        // 3. Update Branch logo if branch_id is provided or authenticated user belongs to branch
        $branchId = $request->input('branch_id');
        if (!$branchId && $request->user() && $request->user()->branch_id) {
            $branchId = $request->user()->branch_id;
        }

        $branch = null;
        if ($branchId) {
            $branch = Branch::find($branchId);
            if ($branch) {
                $branch->logo = $url;
                $branch->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logo image uploaded successfully.',
            'logo' => $url,
            'logo_url' => $url,
            'data' => [
                'logo' => $url,
                'logo_url' => $url,
                'clinic_name' => $globalSetting->clinic_name,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'logo' => $branch->logo,
                ] : null,
                'updated_at' => $globalSetting->updated_at,
            ]
        ], 200);
    }

    /**
     * Get Current Clinic Logo
     * GET /api/settings/logo
     */
    public function getLogo(Request $request)
    {
        $globalSetting = GlobalSetting::getSettings();
        $branchId = $request->query('branch_id');
        $branchLogo = null;
        if ($branchId) {
            $branch = Branch::find($branchId);
            $branchLogo = $branch?->logo;
        }

        $logoUrl = $branchLogo ?: $globalSetting->logo;

        return response()->json([
            'success' => true,
            'logo' => $logoUrl,
            'logo_url' => $logoUrl,
            'clinic_name' => $globalSetting->clinic_name,
            'data' => [
                'logo' => $logoUrl,
                'logo_url' => $logoUrl,
                'global_logo' => $globalSetting->logo,
                'branch_logo' => $branchLogo,
                'clinic_name' => $globalSetting->clinic_name,
            ]
        ]);
    }

    /**
     * Delete Clinic Logo
     * DELETE /api/settings/logo
     */
    public function deleteLogo(Request $request)
    {
        $globalSetting = GlobalSetting::getSettings();
        $oldLogo = $globalSetting->logo;

        if ($oldLogo) {
            try {
                $r2Service = app(CloudflareR2Service::class);
                $r2Service->delete($oldLogo);
            } catch (\Throwable $e) {
                // Ignore cleanup error
            }
        }

        $globalSetting->logo = null;
        $globalSetting->save();

        if ($request->input('branch_id')) {
            $branch = Branch::find($request->input('branch_id'));
            if ($branch) {
                $branch->logo = null;
                $branch->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logo removed successfully.',
            'logo' => null,
            'logo_url' => null,
        ]);
    }

    /**
     * Upload Branch-Specific Logo
     * POST /api/branches/{id}/logo
     */
    public function uploadBranchLogo(Request $request, $id)
    {
        $branch = Branch::findOrFail($id);

        $request->validate([
            'logo' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            'image' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            'file' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
        ]);

        $file = $request->file('logo') ?? $request->file('image') ?? $request->file('file');

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide an image file using field name "logo", "image", or "file".'
            ], 422);
        }

        try {
            $r2Service = app(CloudflareR2Service::class);
            $url = $r2Service->upload($file, 'logos/branches');
        } catch (\Throwable $e) {
            Log::warning('R2 branch logo upload failed, falling back to local public storage: ' . $e->getMessage());
            $path = $file->store(app(\App\Services\TenantContext::class)->storagePath('logos/branches'), 'public');
            $url = asset('storage/' . $path);
        }

        $branch->logo = $url;
        $branch->save();

        return response()->json([
            'success' => true,
            'message' => 'Branch logo uploaded successfully.',
            'logo' => $url,
            'logo_url' => $url,
            'data' => [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'logo' => $url,
                'logo_url' => $url,
            ]
        ]);
    }

    /**
     * Get Branch-Specific Logo
     * GET /api/branches/{id}/logo
     */
    public function getBranchLogo($id)
    {
        $branch = Branch::findOrFail($id);
        $globalSetting = GlobalSetting::getSettings();
        $effectiveLogo = $branch->logo ?: $globalSetting->logo;

        return response()->json([
            'success' => true,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'logo' => $branch->logo,
            'effective_logo' => $effectiveLogo,
        ]);
    }

    /**
     * Get QR Code Info Data
     * GET /api/settings/qr-code-info-data or GET /api/qr-code-info-data
     */
    public function getQrCodeInfoData(Request $request)
    {
        $globalSetting = GlobalSetting::getSettings();
        $user = $request->user() ?? auth('sanctum')->user();

        $branch = null;
        $branchId = $request->query('branch_id');
        if ($branchId) {
            $branch = Branch::find($branchId);
        } elseif ($user && $user->branch_id) {
            $branch = $user->branch;
        }

        // Branch-specific QR info can override or fallback to global
        $qrData = ($branch && !empty($branch->qr_code_info_data))
            ? $branch->qr_code_info_data
            : $globalSetting->qr_code_info_data;

        return response()->json([
            'success' => true,
            'qr_code_info_data' => $qrData,
            'data' => [
                'qr_code_info_data' => $qrData,
                'clinic_name' => $globalSetting->clinic_name,
                'address' => $globalSetting->address,
                'phone' => $globalSetting->phone,
                'email' => $globalSetting->email,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'qr_code_info_data' => $branch->qr_code_info_data,
                ] : null,
                'updated_at' => $globalSetting->updated_at,
            ]
        ]);
    }

    /**
     * Update QR Code Info Data
     * POST/PUT /api/settings/qr-code-info-data or POST/PUT /api/qr-code-info-data
     */
    public function updateQrCodeInfoData(Request $request)
    {
        $validated = $request->validate([
            'qr_code_info_data' => 'nullable|string',
            'data' => 'nullable|string',
            'qr_code_data' => 'nullable|string',
            'info' => 'nullable|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $qrData = $validated['qr_code_info_data'] 
            ?? $validated['data'] 
            ?? $validated['qr_code_data'] 
            ?? $validated['info'] 
            ?? $request->getContent();

        // If json-encoded object with qr_code_info_data key
        if (is_string($qrData) && str_starts_with(trim($qrData), '{')) {
            $decoded = json_decode($qrData, true);
            if (is_array($decoded) && isset($decoded['qr_code_info_data'])) {
                $qrData = $decoded['qr_code_info_data'];
            }
        }

        $globalSetting = GlobalSetting::getSettings();
        $globalSetting->qr_code_info_data = $qrData;
        $globalSetting->save();

        $branch = null;
        if (!empty($validated['branch_id'])) {
            $branch = Branch::find($validated['branch_id']);
            if ($branch) {
                $branch->qr_code_info_data = $qrData;
                $branch->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'QR code info data updated successfully.',
            'qr_code_info_data' => $qrData,
            'data' => [
                'qr_code_info_data' => $qrData,
                'branch_id' => $branch ? $branch->id : null,
                'updated_at' => $globalSetting->updated_at,
            ]
        ]);
    }
}
