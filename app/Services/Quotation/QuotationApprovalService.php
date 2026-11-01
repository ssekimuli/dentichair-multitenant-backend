<?php

namespace App\Services\Quotation;

use App\Models\ActivityLog;
use App\Models\Quotation;
use App\Models\QuotationAccessToken;
use App\Models\QuotationApproval;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationApprovalService
{
    public function __construct(
        protected QuotationStatusService $statusService
    ) {}

    /**
     * Staff records approval for quotation.
     */
    public function recordStaffApproval(Quotation $quotation, array $data, ?int $staffId = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $data, $staffId) {
            $this->statusService->checkAndMarkExpired($quotation);

            if ($quotation->status !== 'sent') {
                throw ValidationException::withMessages([
                    'status' => ["Only 'sent' quotations can be approved. This quotation is currently '{$quotation->status}'."]
                ]);
            }

            if ($quotation->is_expired) {
                throw ValidationException::withMessages([
                    'status' => ['This quotation has expired and cannot be approved without renewal.']
                ]);
            }

            $approvalMethod = $data['approval_method'] ?? 'clinic_staff';
            $customerName = $data['customer_name'] ?? $quotation->customer_name ?? 'Customer';

            QuotationApproval::create([
                'quotation_id' => $quotation->id,
                'approval_method' => $approvalMethod,
                'decision' => 'approved',
                'approved_by' => $staffId,
                'customer_name' => $customerName,
                'customer_email' => $data['customer_email'] ?? $quotation->customer_email,
                'customer_phone' => $data['customer_phone'] ?? $quotation->customer_phone,
                'notes' => $data['notes'] ?? null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'approved_at' => now(),
            ]);

            $this->statusService->transition(
                $quotation,
                'approved',
                $staffId,
                'staff',
                $data['notes'] ?? 'Quotation approved by clinic staff.'
            );

            return $quotation->fresh(['approvals', 'statusHistories']);
        });
    }

    /**
     * Staff records customer rejection.
     */
    public function recordStaffRejection(Quotation $quotation, array $data, ?int $staffId = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $data, $staffId) {
            if (!in_array($quotation->status, ['sent', 'draft'])) {
                throw ValidationException::withMessages([
                    'status' => ["Quotation cannot be rejected from status '{$quotation->status}'."]
                ]);
            }

            $customerName = $data['customer_name'] ?? $quotation->customer_name ?? 'Customer';

            QuotationApproval::create([
                'quotation_id' => $quotation->id,
                'approval_method' => 'clinic_staff',
                'decision' => 'rejected',
                'approved_by' => $staffId,
                'customer_name' => $customerName,
                'customer_email' => $data['customer_email'] ?? $quotation->customer_email,
                'customer_phone' => $data['customer_phone'] ?? $quotation->customer_phone,
                'notes' => $data['reason'] ?? $data['notes'] ?? null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'approved_at' => now(),
            ]);

            $this->statusService->transition(
                $quotation,
                'rejected',
                $staffId,
                'staff',
                $data['reason'] ?? 'Quotation rejected by customer.'
            );

            return $quotation->fresh(['approvals', 'statusHistories']);
        });
    }

    /**
     * Generate a new secure public approval access token for a quotation.
     * Returns the raw unhashed token string to send to the customer.
     */
    public function generatePublicToken(Quotation $quotation, int $daysValid = 30): string
    {
        // Invalidate any prior active tokens for this quotation
        QuotationAccessToken::where('quotation_id', $quotation->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $expiresAt = $quotation->valid_until
            ? Carbon::parse($quotation->valid_until)->endOfDay()
            : now()->addDays($daysValid);

        QuotationAccessToken::create([
            'quotation_id' => $quotation->id,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        return $rawToken;
    }

    /**
     * Validate a raw public access token and return the associated Quotation and Token record.
     */
    public function validatePublicToken(string $rawToken): array
    {
        $tokenHash = hash('sha256', $rawToken);
        $tokenRecord = QuotationAccessToken::with('quotation.branch', 'quotation.items')
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$tokenRecord) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or unknown quotation access token.']
            ]);
        }

        if ($tokenRecord->revoked_at !== null) {
            throw ValidationException::withMessages([
                'token' => ['This access link has been revoked. Please contact the clinic for an updated quote.']
            ]);
        }

        if ($tokenRecord->used_at !== null) {
            throw ValidationException::withMessages([
                'token' => ['This quotation access link has already been used.']
            ]);
        }

        if (Carbon::now()->gt($tokenRecord->expires_at)) {
            throw ValidationException::withMessages([
                'token' => ['This quotation access link has expired.']
            ]);
        }

        $quotation = $tokenRecord->quotation;
        if (!$quotation) {
            throw ValidationException::withMessages([
                'token' => ['The quotation associated with this link is not found.']
            ]);
        }

        // Check if quotation itself is expired
        if ($quotation->is_expired) {
            throw ValidationException::withMessages([
                'status' => ['This quotation has expired and can no longer be approved.']
            ]);
        }

        return [$quotation, $tokenRecord];
    }

    /**
     * Customer approves quotation through public link.
     */
    public function publicApprove(string $rawToken, array $data): Quotation
    {
        return DB::transaction(function () use ($rawToken, $data) {
            [$quotation, $tokenRecord] = $this->validatePublicToken($rawToken);

            if ($quotation->status !== 'sent') {
                throw ValidationException::withMessages([
                    'status' => ["This quotation cannot be approved in its current status: '{$quotation->status}'."]
                ]);
            }

            // Mark token as used
            $tokenRecord->update(['used_at' => now()]);

            $approvalMethod = $quotation->customer_type === 'patient' ? 'patient_link' : 'lab_client_link';
            $customerName = $data['customer_name'] ?? $quotation->customer_name ?? 'Customer';

            QuotationApproval::create([
                'quotation_id' => $quotation->id,
                'approval_method' => $approvalMethod,
                'decision' => 'approved',
                'customer_name' => $customerName,
                'customer_email' => $data['customer_email'] ?? $quotation->customer_email,
                'customer_phone' => $data['customer_phone'] ?? $quotation->customer_phone,
                'notes' => $data['notes'] ?? 'Customer confirmed acceptance via online portal.',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'approved_at' => now(),
            ]);

            $this->statusService->transition(
                $quotation,
                'approved',
                null,
                'public_link',
                'Online approval confirmed by customer.'
            );

            return $quotation->fresh(['items', 'approvals']);
        });
    }

    /**
     * Customer rejects quotation through public link.
     */
    public function publicReject(string $rawToken, array $data): Quotation
    {
        return DB::transaction(function () use ($rawToken, $data) {
            [$quotation, $tokenRecord] = $this->validatePublicToken($rawToken);

            if ($quotation->status !== 'sent') {
                throw ValidationException::withMessages([
                    'status' => ["This quotation cannot be rejected in its current status: '{$quotation->status}'."]
                ]);
            }

            // Mark token as used
            $tokenRecord->update(['used_at' => now()]);

            $approvalMethod = $quotation->customer_type === 'patient' ? 'patient_link' : 'lab_client_link';
            $customerName = $data['customer_name'] ?? $quotation->customer_name ?? 'Customer';

            QuotationApproval::create([
                'quotation_id' => $quotation->id,
                'approval_method' => $approvalMethod,
                'decision' => 'rejected',
                'customer_name' => $customerName,
                'customer_email' => $data['customer_email'] ?? $quotation->customer_email,
                'customer_phone' => $data['customer_phone'] ?? $quotation->customer_phone,
                'notes' => $data['reason'] ?? $data['notes'] ?? 'Declined by customer.',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'approved_at' => now(),
            ]);

            $this->statusService->transition(
                $quotation,
                'rejected',
                null,
                'public_link',
                $data['reason'] ?? 'Customer declined quotation via online link.'
            );

            return $quotation->fresh(['items', 'approvals']);
        });
    }
}

