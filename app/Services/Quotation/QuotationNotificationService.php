<?php

namespace App\Services\Quotation;

use App\Models\ActivityLog;
use App\Models\Quotation;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class QuotationNotificationService
{
    public function __construct(
        protected QuotationApprovalService $approvalService,
        protected QuotationStatusService $statusService,
        protected QuotationPdfService $pdfService
    ) {}

    /**
     * Send quotation via selected channels (email, whatsapp, sms).
     */
    public function send(
        Quotation $quotation,
        array $channels = ['email'],
        ?string $customMessage = null,
        ?int $userId = null,
        array $recipientOptions = []
    ): array {
        $rawToken = $this->approvalService->generatePublicToken($quotation);
        $baseUrl = config('app.url', 'http://localhost');
        $approvalUrl = rtrim($baseUrl, '/') . "/public/quotations/{$rawToken}";

        // If quotation was in draft, transition to sent
        if ($quotation->status === 'draft') {
            $this->statusService->transition(
                $quotation,
                'sent',
                $userId,
                'staff',
                'Quotation dispatched to customer.'
            );
        }

        // Resolve recipients with fallback to quotation customer record
        $recipientEmail = $recipientOptions['email'] ?? null;
        if (empty($recipientEmail)) {
            $recipientEmail = $quotation->customer_email;
        }

        $recipientPhone = $recipientOptions['phone']
            ?? ($recipientOptions['phone_number']
            ?? ($recipientOptions['telephone_number']
            ?? null));
        if (empty($recipientPhone)) {
            $recipientPhone = $quotation->customer_phone;
        }

        // Sync contact details back to customer profile if they were missing
        if (!empty($recipientOptions['email'])) {
            if ($quotation->customer_type === 'patient' && $quotation->patient && empty($quotation->patient->email)) {
                $quotation->patient->update(['email' => $recipientOptions['email']]);
            } elseif ($quotation->customer_type === 'lab_client' && $quotation->labClient && empty($quotation->labClient->email)) {
                $quotation->labClient->update(['email' => $recipientOptions['email']]);
            }
        }

        $providedPhone = $recipientOptions['phone']
            ?? ($recipientOptions['phone_number']
            ?? ($recipientOptions['telephone_number']
            ?? null));
        if (!empty($providedPhone)) {
            if ($quotation->customer_type === 'patient' && $quotation->patient && empty($quotation->patient->phone)) {
                $quotation->patient->update(['phone' => $providedPhone]);
            } elseif ($quotation->customer_type === 'lab_client' && $quotation->labClient && empty($quotation->labClient->phone)) {
                $quotation->labClient->update(['phone' => $providedPhone]);
            }
        }

        $customerName = $quotation->customer_name ?? 'Valued Customer';
        $clinicName = $quotation->branch?->name ?? 'Dental Clinic';
        $totalFormatted = number_format($quotation->total, 2) . ' ' . $quotation->currency;

        $defaultMessage = "Dear {$customerName}, your quotation #{$quotation->quotation_number} from {$clinicName} for {$totalFormatted} is ready. Review and approve online here: {$approvalUrl}";
        $messageText = !empty($customMessage) ? "{$customMessage}\n\nReview link: {$approvalUrl}" : $defaultMessage;

        // Email description: do not include the review link since the PDF document is directly attached
        $defaultEmailMessage = "Dear {$customerName}, please find attached your quotation from {$clinicName} for {$totalFormatted}.\n\nThank you,\n{$clinicName}";
        $emailBody = !empty($customMessage) ? $customMessage : $defaultEmailMessage;
        $emailBody = preg_replace('/(\r?\n)*Review link:\s*[^\s]+/i', '', $emailBody);
        $emailBody = preg_replace('/(\r?\n)*Review and approve online here:\s*[^\s]+/i', '', $emailBody);
        $emailBody = trim($emailBody);

        // Generate PDF when Email or WhatsApp is selected
        $pdfBinary = null;
        $pdfFileName = "quotation-{$quotation->quotation_number}.pdf";
        $mediaUrl = null;
        $r2Path = null;
        $localRelPath = null;
        $localTempPath = null;

        if (in_array('email', $channels) || in_array('whatsapp', $channels)) {
            $pdfBinary = $this->pdfService->generatePdf($quotation);

            // R2 storage is only temporarily used for WhatsApp media dispatch if needed
            if (in_array('whatsapp', $channels)) {
                if (config('filesystems.disks.r2.url') && config('filesystems.disks.r2.key')) {
                    try {
                        $r2Path = "quotations/{$pdfFileName}";
                        Storage::disk('r2')->put($r2Path, $pdfBinary, 'public');
                        $mediaUrl = rtrim(config('filesystems.disks.r2.url'), '/') . '/' . $r2Path;
                    } catch (\Throwable $e) {
                        Log::warning("Failed to store quotation PDF in R2: " . $e->getMessage());
                    }
                }
            }

            if (!$mediaUrl) {
                $mediaUrl = url("/public/quotations/{$rawToken}/pdf");
            }
        }

        $results = [
            'approval_url' => $approvalUrl,
            'token' => $rawToken,
            'pdf_url' => $mediaUrl,
            'channels' => [],
        ];

        if (in_array('email', $channels)) {
            $results['email'] = $recipientEmail;
        }
        if (in_array('whatsapp', $channels)) {
            $results['phone'] = $recipientPhone;
        }
        if (in_array('sms', $channels)) {
            $results['telephone_number'] = $recipientPhone;
            if (!isset($results['phone'])) {
                $results['phone'] = $recipientPhone;
            }
        }

        try {
            // 1. Email Channel (Gmail / Email with PDF attachment and description, without review link)
            if (in_array('email', $channels)) {
                if (!empty($recipientEmail)) {
                    try {
                        Mail::raw($emailBody, function ($m) use ($recipientEmail, $quotation, $clinicName, $pdfBinary, $pdfFileName) {
                            $m->to($recipientEmail)
                              ->subject("Quotation- from {$clinicName}");
                            if ($pdfBinary) {
                                $m->attachData($pdfBinary, $pdfFileName, ['mime' => 'application/pdf']);
                            }
                        });
                        $results['channels']['email'] = [
                            'success' => true,
                            'recipient' => $recipientEmail,
                            'has_pdf' => true,
                        ];
                    } catch (\Throwable $e) {
                        Log::error("Failed to send quotation email: " . $e->getMessage(), ['quotation_id' => $quotation->id]);
                        $results['channels']['email'] = [
                            'success' => false,
                            'recipient' => $recipientEmail,
                            'error' => $e->getMessage(),
                        ];
                    }
                } else {
                    $results['channels']['email'] = ['success' => false, 'error' => 'No email address on file or provided.'];
                }
            }

            // 2. WhatsApp Channel (PDF media + description caption)
            if (in_array('whatsapp', $channels)) {
                if (!empty($recipientPhone)) {
                    $res = NotificationService::sendMediaWhatsApp(
                        $recipientPhone,
                        $mediaUrl,
                        $pdfFileName,
                        $messageText
                    );

                    // Fallback to text WhatsApp if media dispatch encounters an issue
                    if (isset($res['success']) && !$res['success']) {
                        $textRes = NotificationService::sendWhatsApp($recipientPhone, $messageText);
                        if (!empty($textRes['success'])) {
                            $res = $textRes;
                            $res['fallback_to_text'] = true;
                        }
                    }

                    $results['channels']['whatsapp'] = array_merge($res, [
                        'recipient' => $recipientPhone,
                        'has_pdf' => empty($res['fallback_to_text']),
                        'media_url' => $mediaUrl,
                    ]);
                } else {
                    $results['channels']['whatsapp'] = ['success' => false, 'error' => 'No phone number on file or provided.'];
                }
            }

            // 3. SMS Channel (Plain text only with quotation description & link, no PDF)
            if (in_array('sms', $channels)) {
                if (!empty($recipientPhone)) {
                    $res = NotificationService::sendSms($recipientPhone, $messageText);
                    $results['channels']['sms'] = array_merge($res, [
                        'recipient' => $recipientPhone,
                        'has_pdf' => false,
                    ]);
                } else {
                    $results['channels']['sms'] = ['success' => false, 'error' => 'No telephone number on file or provided.'];
                }
            }

            ActivityLog::log(
                'Quotation Dispatched',
                "Quotation #{$quotation->quotation_number} sent to customer via channels: " . implode(', ', $channels),
                [
                    'quotation_id' => $quotation->id,
                    'channels' => $channels,
                    'email' => $recipientEmail,
                    'phone' => $recipientPhone,
                    'approval_url' => $approvalUrl,
                ]
            );

            return $results;
        } finally {
            // Automatically clean up and remove quotation PDF from storage (R2, public, local) immediately after sending
            if ($r2Path) {
                try {
                    if (Storage::disk('r2')->exists($r2Path)) {
                        Storage::disk('r2')->delete($r2Path);
                    }
                } catch (\Throwable $e) {
                    Log::warning("Could not delete quotation PDF from R2: " . $e->getMessage());
                }
            }

            if ($localRelPath) {
                try {
                    if (Storage::disk('public')->exists($localRelPath)) {
                        Storage::disk('public')->delete($localRelPath);
                    }
                } catch (\Throwable $e) {
                    Log::warning("Could not delete quotation PDF from public storage: " . $e->getMessage());
                }
            }

            if ($localTempPath && file_exists($localTempPath)) {
                @unlink($localTempPath);
            }
        }
    }
}
