<?php

namespace App\Services\Quotation;

use App\Models\ActivityLog;
use App\Models\Quotation;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class QuotationNotificationService
{
    public function __construct(
        protected QuotationApprovalService $approvalService,
        protected QuotationStatusService $statusService
    ) {}

    /**
     * Send quotation via selected channels (email, whatsapp, sms).
     */
    public function send(Quotation $quotation, array $channels = ['email'], ?string $customMessage = null, ?int $userId = null): array
    {
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

        $results = [
            'approval_url' => $approvalUrl,
            'token' => $rawToken,
            'channels' => [],
        ];

        $customerName = $quotation->customer_name ?? 'Valued Customer';
        $clinicName = $quotation->branch?->name ?? 'Dental Clinic';
        $totalFormatted = number_format($quotation->total, 2) . ' ' . $quotation->currency;

        $defaultMessage = "Dear {$customerName}, your quotation #{$quotation->quotation_number} from {$clinicName} for {$totalFormatted} is ready. Review and approve online here: {$approvalUrl}";
        $messageText = !empty($customMessage) ? "{$customMessage}\n\nReview link: {$approvalUrl}" : $defaultMessage;

        // 1. Email Channel
        if (in_array('email', $channels)) {
            $email = $quotation->customer_email;
            if (!empty($email)) {
                try {
                    Mail::raw($messageText, function ($m) use ($email, $quotation, $clinicName) {
                        $m->to($email)
                          ->subject("Quotation #{$quotation->quotation_number} from {$clinicName}");
                    });
                    $results['channels']['email'] = ['success' => true, 'recipient' => $email];
                } catch (\Throwable $e) {
                    Log::error("Failed to send quotation email: " . $e->getMessage(), ['quotation_id' => $quotation->id]);
                    $results['channels']['email'] = ['success' => false, 'error' => $e->getMessage()];
                }
            } else {
                $results['channels']['email'] = ['success' => false, 'error' => 'No email address on file.'];
            }
        }

        // 2. WhatsApp Channel
        if (in_array('whatsapp', $channels)) {
            $phone = $quotation->customer_phone;
            if (!empty($phone)) {
                $res = NotificationService::sendWhatsApp($phone, $messageText);
                $results['channels']['whatsapp'] = $res;
            } else {
                $results['channels']['whatsapp'] = ['success' => false, 'error' => 'No phone number on file.'];
            }
        }

        // 3. SMS Channel
        if (in_array('sms', $channels)) {
            $phone = $quotation->customer_phone;
            if (!empty($phone)) {
                $res = NotificationService::sendSms($phone, $messageText);
                $results['channels']['sms'] = $res;
            } else {
                $results['channels']['sms'] = ['success' => false, 'error' => 'No phone number on file.'];
            }
        }

        ActivityLog::log(
            'Quotation Dispatched',
            "Quotation #{$quotation->quotation_number} sent to customer via channels: " . implode(', ', $channels),
            [
                'quotation_id' => $quotation->id,
                'channels' => $channels,
                'approval_url' => $approvalUrl,
            ]
        );

        return $results;
    }
}

