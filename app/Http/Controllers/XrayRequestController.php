<?php

namespace App\Http\Controllers;

use App\Models\XrayRequest;
use App\Models\Patient;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\InvoiceOtherCharge;
use App\Services\CloudflareR2Service;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class XrayRequestController extends Controller
{
    /**
     * Helper to upload a file to R2 or public storage
     */
    protected function uploadAttachment($file): array
    {
        $originalName = $file->getClientOriginalName();
        $mime = $file->getClientMimeType();
        $size = $file->getSize();
        $url = null;

        try {
            if (config('filesystems.disks.r2.key')) {
                $service = app(CloudflareR2Service::class);
                $url = $service->upload($file, 'xray-requests');
            }
        } catch (\Throwable $e) {
            Log::warning('R2 upload failed, using local disk: ' . $e->getMessage());
        }

        if (!$url) {
            $path = $file->store('xray-requests', 'public');
            $url = asset('storage/' . $path);
        }

        return [
            'url'  => $url,
            'name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'uploaded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Display a listing of all X-ray / Radiology requests with search & filters.
     * GET /api/xray-requests
     */
    public function index(Request $request): JsonResponse
    {
        $query = XrayRequest::with([
            'patientRecord:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patientRecord.branch:id,name',
            'branch:id,name',
            'doctor:id,name',
            'invoice:id,amount,paid_amount,status,issue_date'
        ]);

        // Branch filter
        if ($request->filled('branch_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id)
                  ->orWhereHas('patient', function ($pq) use ($request) {
                      $pq->where('branch_id', $request->branch_id);
                  });
            });
        }

        // Patient filter
        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Facility filter
        if ($request->filled('facility') && $request->facility !== 'all') {
            $query->where('facility', 'like', "%{$request->facility}%");
        }

        // X-ray type filter
        if ($request->filled('xray_type') && $request->xray_type !== 'all') {
            $query->where('xray_type', 'like', "%{$request->xray_type}%");
        }

        // Date filters
        if ($request->filled('date')) {
            $query->whereDate('request_date', $request->date);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('request_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('request_date', '<=', $request->date_to);
        }

        // Clinician filter
        if ($request->filled('doctor_name')) {
            $query->where('doctor_name', 'like', "%{$request->doctor_name}%");
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        // Multi-field text search (includes direct patient string)
        if ($request->filled('search')) {
            $term = trim($request->search);
            $query->where(function ($q) use ($term) {
                $q->where('patient', 'like', "%{$term}%")
                  ->orWhere('facility', 'like', "%{$term}%")
                  ->orWhere('xray_type', 'like', "%{$term}%")
                  ->orWhere('tooth_region', 'like', "%{$term}%")
                  ->orWhere('clinical_notes', 'like', "%{$term}%")
                  ->orWhere('doctor_name', 'like', "%{$term}%")
                  ->orWhereHas('patient', function ($pq) use ($term) {
                      $pq->where('first_name', 'like', "%{$term}%")
                         ->orWhere('last_name', 'like', "%{$term}%")
                         ->orWhere('phone', 'like', "%{$term}%")
                         ->orWhere('email', 'like', "%{$term}%")
                         ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$term}%");
                  });
            });
        }

        // Sort by date desc
        $query->orderBy('request_date', 'desc')->orderBy('id', 'desc');

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $query->paginate($perPage);

        return response()->json($paginated);
    }

    /**
     * Store a newly created X-ray request in storage & automatically generate an Invoice.
     * POST /api/xray-requests
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient' => 'nullable|string|max:255',
            'patient_id' => 'nullable|exists:patients,id',
            'facility' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'xray_type' => 'nullable|string|max:255',
            'tooth_region' => 'nullable|string|max:255',
            'clinical_notes' => 'nullable|string',
            'doctor_name' => 'nullable|string|max:255',
            'doctor_id' => 'nullable|integer',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|string|in:pending,completed,cancelled',
            'request_date' => 'nullable|date',
            'completed_date' => 'nullable|date',
            'report_findings' => 'nullable|string',
            'attachments' => 'nullable',
            'files.*' => 'nullable|file|max:25600',
        ]);

        if (empty($validated['request_date'])) {
            $validated['request_date'] = now()->toDateString();
        }
        if (empty($validated['status'])) {
            $validated['status'] = 'pending';
        }

        // Resolve patient name string if patient_id is provided and patient is empty
        $patientModel = null;
        if (!empty($validated['patient_id'])) {
            $patientModel = Patient::find($validated['patient_id']);
            if (empty($validated['patient']) && $patientModel) {
                $validated['patient'] = trim($patientModel->first_name . ' ' . $patientModel->last_name);
            }
        }

        // If neither patient nor patient_id was supplied
        if (empty($validated['patient']) && empty($validated['patient_id'])) {
            $validated['patient'] = 'Walk-in Patient';
        }

        // Auto-assign branch from patient if not supplied
        if (empty($validated['branch_id'])) {
            $validated['branch_id'] = $patientModel?->branch_id ?: (Branch::first()?->id ?: 1);
        }

        // Process attachments (existing list + any newly uploaded files)
        $attachments = [];
        if (!empty($validated['attachments'])) {
            $parsed = is_string($validated['attachments']) ? json_decode($validated['attachments'], true) : $validated['attachments'];
            if (is_array($parsed)) {
                $attachments = $parsed;
            }
        }

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $attachments[] = $this->uploadAttachment($file);
            }
        } elseif ($request->hasFile('attachment')) {
            $attachments[] = $this->uploadAttachment($request->file('attachment'));
        }

        $validated['attachments'] = $attachments;

        return DB::transaction(function () use ($validated, $patientModel) {
            $xrayRequest = XrayRequest::create($validated);

            // ── Automatically Generate Invoice for the Patient ──
            $amount = (float) $xrayRequest->amount;
            if ($amount > 0) {
                $patientDisplayName = $xrayRequest->patient ?: ($patientModel ? trim($patientModel->first_name . ' ' . $patientModel->last_name) : 'Patient');
                $branchId = $xrayRequest->branch_id ?: ($patientModel?->branch_id ?: (Branch::first()?->id ?: 1));
                $issueDate = $xrayRequest->request_date ? Carbon::parse($xrayRequest->request_date)->toDateString() : now()->toDateString();
                $dueDate = Carbon::parse($issueDate)->addDays(14)->toDateString();

                $desc = 'Radiology / X-Ray Requisition: ' . ($xrayRequest->xray_type ?: 'Dental Imaging') .
                        ' (' . $xrayRequest->facility . ')' .
                        (!empty($xrayRequest->tooth_region) ? ' - ' . $xrayRequest->tooth_region : '');

                // Check for existing open Pending invoice for same patient on same date
                $existingInvoice = null;
                if ($xrayRequest->patient_id) {
                    $existingInvoice = Invoice::where('patient_id', $xrayRequest->patient_id)
                        ->whereDate('issue_date', $issueDate)
                        ->where('branch_id', $branchId)
                        ->whereIn('status', ['Pending', 'Overdue'])
                        ->first();
                }

                if ($existingInvoice) {
                    $existingInvoice->amount += $amount;
                    $existingInvoice->save();
                    $invoice = $existingInvoice;
                } else {
                    $invoice = Invoice::create([
                        'patient_name' => $patientDisplayName,
                        'patient_id' => $xrayRequest->patient_id ?: null,
                        'amount' => $amount,
                        'paid_amount' => $amount,
                        'status' => 'Paid',
                        'issue_date' => $issueDate,
                        'due_date' => $dueDate,
                        'branch_id' => $branchId,
                        'category' => 'radiology-income',
                        'employee_id' => $xrayRequest->doctor_id ?: null,
                    ]);
                }

                // Add item to other charges
                $invoice->otherCharges()->create([
                    'description' => $desc,
                    'amount' => $amount,
                ]);

                // Link invoice to X-ray request
                $xrayRequest->invoice_id = $invoice->id;
                $xrayRequest->save();
            }

            $xrayRequest->load([
                'patientRecord:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
                'patientRecord.branch:id,name',
                'branch:id,name',
                'invoice:id,amount,paid_amount,status,issue_date'
            ]);

            return response()->json([
                'message' => 'X-ray request and invoice created successfully',
                'data' => $xrayRequest
            ], 201);
        });
    }

    /**
     * Display the specified X-ray request.
     * GET /api/xray-requests/{id}
     */
    public function show($id): JsonResponse
    {
        $xrayRequest = XrayRequest::with([
            'patientRecord:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patientRecord.branch:id,name',
            'branch:id,name',
            'doctor:id,name',
            'invoice:id,amount,paid_amount,status,issue_date'
        ])->findOrFail($id);

        return response()->json([
            'data' => $xrayRequest
        ]);
    }

    /**
     * Update the specified X-ray request in storage.
     * PUT /api/xray-requests/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $xrayRequest = XrayRequest::findOrFail($id);

        $validated = $request->validate([
            'patient' => 'nullable|string|max:255',
            'patient_id' => 'nullable|exists:patients,id',
            'facility' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'xray_type' => 'nullable|string|max:255',
            'tooth_region' => 'nullable|string|max:255',
            'clinical_notes' => 'nullable|string',
            'doctor_name' => 'nullable|string|max:255',
            'doctor_id' => 'nullable|integer',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|string|in:pending,completed,cancelled',
            'request_date' => 'nullable|date',
            'completed_date' => 'nullable|date',
            'report_findings' => 'nullable|string',
            'attachments' => 'nullable',
            'files.*' => 'nullable|file|max:25600',
        ]);

        // Process attachments
        $currentAttachments = $xrayRequest->attachments ?: [];
        if (!empty($validated['attachments'])) {
            $parsed = is_string($validated['attachments']) ? json_decode($validated['attachments'], true) : $validated['attachments'];
            if (is_array($parsed)) {
                $currentAttachments = $parsed;
            }
        }

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $currentAttachments[] = $this->uploadAttachment($file);
            }
        } elseif ($request->hasFile('attachment')) {
            $currentAttachments[] = $this->uploadAttachment($request->file('attachment'));
        }

        $validated['attachments'] = $currentAttachments;

        // If patient_id given and patient string empty
        if (!empty($validated['patient_id']) && empty($validated['patient'])) {
            $patientModel = Patient::find($validated['patient_id']);
            if ($patientModel) {
                $validated['patient'] = trim($patientModel->first_name . ' ' . $patientModel->last_name);
            }
        }

        $xrayRequest->update($validated);
        $xrayRequest->load([
            'patientRecord:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patientRecord.branch:id,name',
            'branch:id,name',
            'invoice:id,amount,paid_amount,status,issue_date'
        ]);

        return response()->json([
            'message' => 'X-ray request updated successfully',
            'data' => $xrayRequest
        ]);
    }

    /**
     * Upload an attachment to an existing X-ray request.
     * POST /api/xray-requests/{id}/attachments
     */
    public function uploadFiles(Request $request, $id): JsonResponse
    {
        $xrayRequest = XrayRequest::findOrFail($id);

        $request->validate([
            'files' => 'required_without:attachment|array',
            'files.*' => 'file|max:25600',
            'attachment' => 'nullable|file|max:25600',
        ]);

        $attachments = $xrayRequest->attachments ?: [];

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $attachments[] = $this->uploadAttachment($file);
            }
        } elseif ($request->hasFile('attachment')) {
            $attachments[] = $this->uploadAttachment($request->file('attachment'));
        }

        $xrayRequest->update(['attachments' => $attachments]);

        return response()->json([
            'message' => 'File(s) uploaded successfully',
            'data' => $xrayRequest
        ]);
    }

    /**
     * Remove the specified X-ray request from storage.
     * DELETE /api/xray-requests/{id}
     */
    public function destroy($id): JsonResponse
    {
        $xrayRequest = XrayRequest::findOrFail($id);
        $xrayRequest->delete();

        return response()->json([
            'message' => 'X-ray request deleted successfully'
        ]);
    }

    /**
     * Print Requisition Form for the X-ray request.
     * GET /api/xray-requests/{id}/print
     */
    public function print($id)
    {
        $xrayRequest = XrayRequest::with([
            'patientRecord:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patientRecord.branch:id,name',
            'branch:id,name',
            'doctor:id,name',
            'invoice:id,amount,paid_amount,status,issue_date'
        ])->findOrFail($id);

        $clinicName = config('app.name', 'DENTAL CLINIC');
        $clinicDetails = 'Oral & Maxillofacial Diagnostic Center';
        $clinicAddress = '';
        $clinicPhone = '';
        $clinicEmail = '';

        if (Schema::hasTable('businesses')) {
            $business = Business::first();
            if ($business) {
                $clinicName = $business->name ?: $clinicName;
                $clinicDetails = $business->tagline ?: $clinicDetails;
                $clinicAddress = $business->address ?: $clinicAddress;
                $clinicPhone = $business->phone ?: $clinicPhone;
                $clinicEmail = $business->email ?: $clinicEmail;
            }
        }

        if ($xrayRequest->branch) {
            $clinicName = $xrayRequest->branch->name ?: $clinicName;
            $clinicAddress = $xrayRequest->branch->address ?: $clinicAddress;
            $clinicPhone = $xrayRequest->branch->phone ?: $clinicPhone;
        }

        return view('xray-requests.print', compact(
            'xrayRequest',
            'clinicName',
            'clinicDetails',
            'clinicAddress',
            'clinicPhone',
            'clinicEmail'
        ));
    }

    /**
     * Send X-Ray / Radiology requisition details and/or attached files via Email or WhatsApp.
     * POST /api/xray-requests/{id}/send
     */
    public function sendRequisition(Request $request, $id): JsonResponse
    {
        $xrayRequest = XrayRequest::with([
            'patientRecord',
            'branch',
            'doctor',
            'invoice'
        ])->findOrFail($id);

        $validated = $request->validate([
            'channel' => 'required|in:email,whatsapp',
            'recipient' => 'required|string',
            'custom_message' => 'nullable|string',
            'file_url' => 'nullable|string',
            'file_name' => 'nullable|string',
        ]);

        $channel = $validated['channel'];
        $recipient = trim($validated['recipient']);
        $customMessage = $validated['custom_message'] ?? '';
        $fileUrl = $validated['file_url'] ?? null;
        $fileName = $validated['file_name'] ?? null;

        $settings = \App\Models\GlobalSetting::getSettings();
        $clinicName = $settings->clinic_name ?? config('app.name', 'Dental Clinic');

        $patientName = $xrayRequest->patient
            ?: ($xrayRequest->patientRecord ? trim($xrayRequest->patientRecord->first_name . ' ' . $xrayRequest->patientRecord->last_name) : 'Patient');

        $links = [];
        $mailAttachments = [];

        if ($fileUrl) {
            $links[] = [
                'name' => $fileName ?: 'Radiology Scan',
                'url' => $fileUrl,
            ];
        } else {
            $attachments = $xrayRequest->attachments ?: [];
            foreach ($attachments as $idx => $att) {
                $url = is_array($att) ? ($att['url'] ?? '') : $att;
                $name = is_array($att) ? ($att['name'] ?? ('Scan File ' . ($idx + 1))) : ('Scan File ' . ($idx + 1));
                if ($url) {
                    $links[] = ['name' => $name, 'url' => $url];
                }
            }
        }

        // Prepare mail attachments if applicable
        foreach ($links as $link) {
            $url = $link['url'];
            $storageUrl = url('/storage');
            if (str_starts_with($url, $storageUrl)) {
                $localPath = str_replace($storageUrl, storage_path('app/public'), $url);
                if (file_exists($localPath)) {
                    $mailAttachments[] = \Illuminate\Mail\Mailables\Attachment::fromPath($localPath);
                }
            } else {
                try {
                    $mailAttachments[] = \Illuminate\Mail\Mailables\Attachment::fromUrl($url);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Could not attach remote scan to email: {$url}. " . $e->getMessage());
                }
            }
        }

        // Send Email
        if ($channel === 'email') {
            $subject = "Radiology Requisition #" . str_pad($xrayRequest->id, 5, '0', STR_PAD_LEFT) . " - {$patientName} ({$clinicName})";
            $viewData = [
                'name' => $patientName,
                'clinicName' => $clinicName,
                'links' => $links,
                'customMessage' => $customMessage,
                'requisition' => $xrayRequest,
            ];

            try {
                $mail = new \App\Mail\GenericMarkdownMail(
                    'emails.patient-documents',
                    $viewData,
                    $subject,
                    $mailAttachments
                );
                \Illuminate\Support\Facades\Mail::to($recipient)->send($mail);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Email send failed for requisition #{$xrayRequest->id}: " . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to send email: ' . $e->getMessage()
                ], 500);
            }
        }

        // Send WhatsApp
        if ($channel === 'whatsapp') {
            $cleanPhone = preg_replace('/[^0-9]/', '', $recipient);
            $msg = "Hello {$patientName}, here are your radiology requisition details from {$clinicName}:\n\n";
            $msg .= "• Requisition #: #" . str_pad($xrayRequest->id, 5, '0', STR_PAD_LEFT) . "\n";
            $msg .= "• Scan Type: " . ($xrayRequest->xray_type ?: 'Dental Imaging') . "\n";
            $msg .= "• Facility: " . $xrayRequest->facility . "\n";
            if ($xrayRequest->tooth_region) {
                $msg .= "• Region: " . $xrayRequest->tooth_region . "\n";
            }
            if (!empty($customMessage)) {
                $msg .= "\nNote: {$customMessage}\n";
            }
            if (!empty($links)) {
                $msg .= "\nAttached Files:\n";
                foreach ($links as $link) {
                    $msg .= "- {$link['name']}: {$link['url']}\n";
                }
            }

            try {
                \App\Services\NotificationService::sendWhatsApp($cleanPhone, $msg);
                foreach ($links as $link) {
                    \App\Services\NotificationService::sendMediaWhatsApp($cleanPhone, $link['url'], $link['name']);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("WhatsApp send warning: " . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Requisition successfully sent via " . ucfirst($channel) . " to {$recipient}.",
            'channel' => $channel,
            'recipient' => $recipient,
        ]);
    }
}
