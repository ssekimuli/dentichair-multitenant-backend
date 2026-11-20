<?php

namespace App\Http\Controllers;

use App\Models\PatientReferral;
use App\Models\Patient;
use App\Models\Branch;
use App\Services\CloudflareR2Service;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PatientReferralController extends Controller
{
    /**
     * Auto-ensure patient_referrals table exists even before artisan migrate is run.
     */
    protected function ensureTableExists(): void
    {
        try {
            if (!Schema::hasTable('patient_referrals')) {
                Schema::create('patient_referrals', function (Blueprint $table) {
                    $table->id();
                    $table->string('client_uuid')->nullable()->index();
                    $table->unsignedBigInteger('patient_id')->nullable()->index();
                    $table->unsignedBigInteger('branch_id')->nullable()->index();
                    $table->string('patient_name')->nullable();
                    $table->string('patient_number')->nullable();
                    $table->string('sex', 50)->nullable();
                    $table->string('age', 50)->nullable();
                    $table->date('first_visit_date')->nullable();
                    $table->date('referral_date')->nullable();
                    $table->string('referred_to')->nullable();
                    $table->string('target_email')->nullable();
                    $table->string('target_phone')->nullable();
                    $table->string('from_health_unit')->nullable();
                    $table->date('unit_date')->nullable();
                    $table->text('history_and_symptoms')->nullable();
                    $table->text('investigations_done')->nullable();
                    $table->text('diagnosis')->nullable();
                    $table->text('treatment_given')->nullable();
                    $table->text('reason_for_referral')->nullable();
                    $table->string('clinician_name')->nullable();
                    $table->string('clinician_phone')->nullable();
                    $table->string('clinician_signature')->nullable();
                    $table->text('further_investigations')->nullable();
                    $table->text('referral_site_diagnosis')->nullable();
                    $table->text('referral_site_treatment')->nullable();
                    $table->text('surveillance_continued')->nullable();
                    $table->text('remarks')->nullable();
                    $table->string('referral_site_clinician')->nullable();
                    $table->string('referral_site_phone')->nullable();
                    $table->string('referral_site_signature')->nullable();
                    $table->string('status', 50)->default('referred');
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            Log::warning('Could not auto-create patient_referrals table: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/patient-referrals
     * GET /api/patients/{patientId}/referrals
     */
    public function index(Request $request, $patientId = null): JsonResponse
    {
        $this->ensureTableExists();

        $query = PatientReferral::with([
            'patient:id,first_name,last_name,gender,date_of_birth,age,phone,email,address,branch_id,created_at',
            'branch:id,name,address,phone,email',
        ]);

        $effectivePatientId = $patientId ?: $request->input('patient_id');
        if (!empty($effectivePatientId)) {
            $query->where('patient_id', $effectivePatientId);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $term = trim($request->search);
            $query->where(function ($q) use ($term) {
                $q->where('patient_name', 'like', "%{$term}%")
                  ->orWhere('patient_number', 'like', "%{$term}%")
                  ->orWhere('referred_to', 'like', "%{$term}%")
                  ->orWhere('diagnosis', 'like', "%{$term}%")
                  ->orWhere('reason_for_referral', 'like', "%{$term}%")
                  ->orWhere('clinician_name', 'like', "%{$term}%");
            });
        }

        $items = $query->orderBy('referral_date', 'desc')->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * POST /api/patient-referrals
     * POST /api/patients/{patientId}/referrals
     */
    public function store(Request $request, $patientId = null): JsonResponse
    {
        $this->ensureTableExists();

        $validated = $request->validate([
            'client_uuid' => 'nullable|string|max:191',
            'patient_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'patient_name' => 'nullable|string|max:255',
            'patient_number' => 'nullable|string|max:100',
            'sex' => 'nullable|string|max:50',
            'age' => 'nullable|string|max:50',
            'first_visit_date' => 'nullable|date',
            'referral_date' => 'nullable|date',
            'referred_to' => 'nullable|string|max:255',
            'target_email' => 'nullable|string|max:255',
            'target_phone' => 'nullable|string|max:100',
            'from_health_unit' => 'nullable|string|max:255',
            'unit_date' => 'nullable|date',
            'history_and_symptoms' => 'nullable|string',
            'investigations_done' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'treatment_given' => 'nullable|string',
            'reason_for_referral' => 'nullable|string',
            'clinician_name' => 'nullable|string|max:255',
            'clinician_phone' => 'nullable|string|max:100',
            'clinician_signature' => 'nullable|string|max:255',
            'further_investigations' => 'nullable|string',
            'referral_site_diagnosis' => 'nullable|string',
            'referral_site_treatment' => 'nullable|string',
            'surveillance_continued' => 'nullable|string',
            'remarks' => 'nullable|string',
            'referral_site_clinician' => 'nullable|string|max:255',
            'referral_site_phone' => 'nullable|string|max:100',
            'referral_site_signature' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:50',
        ]);

        if (!empty($validated['client_uuid'])) {
            $existing = PatientReferral::where('client_uuid', $validated['client_uuid'])->first();
            if ($existing) {
                $existing->load(['patient', 'branch']);
                return response()->json([
                    'success' => true,
                    'message' => 'Referral record already synced.',
                    'data' => $existing,
                ]);
            }
        }

        if ($patientId && empty($validated['patient_id'])) {
            $validated['patient_id'] = (int) $patientId;
        }

        $patientModel = null;
        if (!empty($validated['patient_id'])) {
            $patientModel = Patient::find($validated['patient_id']);
            if ($patientModel) {
                if (empty($validated['patient_name'])) {
                    $validated['patient_name'] = trim($patientModel->first_name . ' ' . $patientModel->last_name);
                }
                if (empty($validated['patient_number'])) {
                    $validated['patient_number'] = 'PAT-' . str_pad((string) $patientModel->id, 3, '0', STR_PAD_LEFT);
                }
                if (empty($validated['sex'])) {
                    $validated['sex'] = $patientModel->gender;
                }
                if (empty($validated['age']) && $patientModel->age !== null) {
                    $validated['age'] = (string) $patientModel->age;
                }
                if (empty($validated['branch_id'])) {
                    $validated['branch_id'] = $patientModel->branch_id;
                }
                if (empty($validated['first_visit_date']) && $patientModel->created_at) {
                    $validated['first_visit_date'] = $patientModel->created_at->toDateString();
                }
            }
        }

        if (empty($validated['referral_date'])) {
            $validated['referral_date'] = now()->toDateString();
        }
        if (empty($validated['unit_date'])) {
            $validated['unit_date'] = $validated['referral_date'];
        }
        if (empty($validated['status'])) {
            $validated['status'] = 'referred';
        }
        if (empty($validated['branch_id'])) {
            $validated['branch_id'] = Branch::first()?->id ?: 1;
        }

        $referral = PatientReferral::create($validated);
        $referral->load(['patient', 'branch']);

        return response()->json([
            'success' => true,
            'message' => 'Patient referral form saved successfully.',
            'data' => $referral,
        ], 201);
    }

    /**
     * GET /api/patient-referrals/{id}
     */
    public function show($id): JsonResponse
    {
        $this->ensureTableExists();

        $referral = is_numeric($id)
            ? PatientReferral::with(['patient', 'branch'])->findOrFail($id)
            : PatientReferral::with(['patient', 'branch'])->where('client_uuid', $id)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $referral,
        ]);
    }

    /**
     * PUT /api/patient-referrals/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $this->ensureTableExists();

        $referral = is_numeric($id)
            ? PatientReferral::find($id)
            : PatientReferral::where('client_uuid', $id)->first();

        if (!$referral && $request->filled('client_uuid')) {
            $referral = PatientReferral::where('client_uuid', $request->client_uuid)->first();
        }

        if (!$referral) {
            return response()->json([
                'success' => false,
                'message' => 'Referral record not found.',
            ], 404);
        }

        $validated = $request->validate([
            'client_uuid' => 'nullable|string|max:191',
            'patient_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'patient_name' => 'nullable|string|max:255',
            'patient_number' => 'nullable|string|max:100',
            'sex' => 'nullable|string|max:50',
            'age' => 'nullable|string|max:50',
            'first_visit_date' => 'nullable|date',
            'referral_date' => 'nullable|date',
            'referred_to' => 'nullable|string|max:255',
            'target_email' => 'nullable|string|max:255',
            'target_phone' => 'nullable|string|max:100',
            'from_health_unit' => 'nullable|string|max:255',
            'unit_date' => 'nullable|date',
            'history_and_symptoms' => 'nullable|string',
            'investigations_done' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'treatment_given' => 'nullable|string',
            'reason_for_referral' => 'nullable|string',
            'clinician_name' => 'nullable|string|max:255',
            'clinician_phone' => 'nullable|string|max:100',
            'clinician_signature' => 'nullable|string|max:255',
            'further_investigations' => 'nullable|string',
            'referral_site_diagnosis' => 'nullable|string',
            'referral_site_treatment' => 'nullable|string',
            'surveillance_continued' => 'nullable|string',
            'remarks' => 'nullable|string',
            'referral_site_clinician' => 'nullable|string|max:255',
            'referral_site_phone' => 'nullable|string|max:100',
            'referral_site_signature' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:50',
        ]);

        $referral->update($validated);
        $referral->load(['patient', 'branch']);

        return response()->json([
            'success' => true,
            'message' => 'Patient referral form updated successfully.',
            'data' => $referral,
        ]);
    }

    /**
     * DELETE /api/patient-referrals/{id}
     */
    public function destroy($id): JsonResponse
    {
        $this->ensureTableExists();

        $referral = is_numeric($id)
            ? PatientReferral::find($id)
            : PatientReferral::where('client_uuid', $id)->first();

        if (!$referral) {
            return response()->json([
                'success' => false,
                'message' => 'Referral record not found.',
            ], 404);
        }

        $referral->delete();

        return response()->json([
            'success' => true,
            'message' => 'Patient referral form deleted successfully.',
        ]);
    }

    /**
     * POST /api/patient-referrals/{id?}/send
     * Shares the referral note (and optional generated PDF) via Email or WhatsApp to Patient or Hospital.
     */
    public function sendReferral(Request $request, $id = null): JsonResponse
    {
        $this->ensureTableExists();

        $referral = null;
        if (!empty($id)) {
            $referral = is_numeric($id)
                ? PatientReferral::with(['patient', 'branch'])->find($id)
                : PatientReferral::with(['patient', 'branch'])->where('client_uuid', $id)->first();
        }

        $validated = $request->validate([
            'channel' => 'required|in:email,whatsapp',
            'recipient' => 'required|string',
            'recipient_type' => 'nullable|in:patient,hospital',
            'custom_message' => 'nullable|string',
            'pdf_base64' => 'nullable|string',
            'file_name' => 'nullable|string',
            'patient_name' => 'nullable|string',
            'patient_number' => 'nullable|string',
            'referred_to' => 'nullable|string',
            'from_health_unit' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'reason_for_referral' => 'nullable|string',
        ]);

        $channel = $validated['channel'];
        $recipient = trim($validated['recipient']);
        $customMessage = $validated['custom_message'] ?? '';
        $pdfBase64 = $validated['pdf_base64'] ?? null;

        $settings = class_exists(\App\Models\GlobalSetting::class) ? \App\Models\GlobalSetting::getSettings() : null;
        $clinicName = $referral?->from_health_unit
            ?: ($validated['from_health_unit'] ?? ($settings?->clinic_name ?? config('app.name', 'Dental Clinic')));

        $patientName = $referral?->patient_name
            ?: ($validated['patient_name'] ?? ($referral?->patient ? trim($referral->patient->first_name . ' ' . $referral->patient->last_name) : 'Patient'));
        $patientNumber = $referral?->patient_number ?: ($validated['patient_number'] ?? 'N/A');
        $referredTo = $referral?->referred_to ?: ($validated['referred_to'] ?? 'Receiving Hospital / Specialist');
        $diagnosis = $referral?->diagnosis ?: ($validated['diagnosis'] ?? 'See attached note');
        $reasonForReferral = $referral?->reason_for_referral ?: ($validated['reason_for_referral'] ?? 'Specialist evaluation / Management');

        $fileName = $validated['file_name'] ?: ('HMS_Form_032_Referral_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $patientName) . '.pdf');

        $links = [];
        $mailAttachments = [];
        $uploadedUrl = null;
        $relPath = null;
        $localTempPath = null;
        $rawBytes = null;

        if (!empty($pdfBase64)) {
            $decoded = base64_decode($pdfBase64);
            if ($decoded !== false && strlen($decoded) > 0) {
                $rawBytes = $decoded;
            }
        }

        try {
            if ($rawBytes) {
                // Attach in-memory to email without saving to storage
                $mailAttachments[] = \Illuminate\Mail\Mailables\Attachment::fromData(
                    fn () => $rawBytes,
                    $fileName
                )->withMime('application/pdf');

                if ($channel === 'whatsapp') {
                    $relPath = app(\App\Services\TenantContext::class)->storagePath(
                        'referrals/' . uniqid('ref_') . '_' . $fileName
                    );
                    Storage::disk('public')->put($relPath, $rawBytes);
                    $localTempPath = storage_path('app/public/' . $relPath);
                    $uploadedUrl = asset('storage/' . $relPath);
                }
            }

            if ($channel === 'email') {
                $subject = "HMS Form 032 Referral Note - {$patientName} (To: {$referredTo})";
                $viewData = [
                    'name' => ($validated['recipient_type'] ?? 'patient') === 'hospital' ? $referredTo : $patientName,
                    'clinicName' => $clinicName,
                    'links' => $links,
                    'customMessage' => $customMessage,
                ];

                try {
                    $mail = new \App\Mail\GenericMarkdownMail(
                        'emails.patient-documents',
                        $viewData,
                        $subject,
                        $mailAttachments
                    );
                    Mail::to($recipient)->send($mail);
                } catch (\Throwable $e) {
                    $err = $e->getMessage();
                    Log::error("Email send failed for referral: " . $err);
                    return response()->json([
                        'success' => false,
                        'error' => 'Mail server error: ' . $err,
                    ], 500);
                }
            }

            if ($channel === 'whatsapp') {
                $cleanPhone = preg_replace('/[^0-9]/', '', $recipient);
                $msg = !empty($customMessage)
                    ? $customMessage
                    : ("*HMS FORM 032: REFERRAL NOTE*\n"
                        . "From: {$clinicName}\n"
                        . "To: {$referredTo}\n"
                        . "Patient: {$patientName} ({$patientNumber})\n"
                        . "Diagnosis: {$diagnosis}\n"
                        . "Reason for Referral: {$reasonForReferral}");

                try {
                    \App\Services\NotificationService::sendWhatsApp($cleanPhone, $msg);
                    if ($uploadedUrl) {
                        \App\Services\NotificationService::sendMediaWhatsApp($cleanPhone, $uploadedUrl, $fileName, "Referral Note - {$patientName}");
                    }
                } catch (\Throwable $e) {
                    Log::warning("WhatsApp referral send warning: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Referral letter dispatched via " . ucfirst($channel) . " to {$recipient}.",
            ]);
        } finally {
            // Automatically clean up and remove any temporary referral PDF from storage
            if ($relPath && Storage::disk('public')->exists($relPath)) {
                Storage::disk('public')->delete($relPath);
            }
            if ($localTempPath && file_exists($localTempPath)) {
                @unlink($localTempPath);
            }
        }
    }
}

