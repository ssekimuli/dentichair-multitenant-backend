<?php

namespace App\Services\Quotation;

use App\Models\ActivityLog;
use App\Models\Quotation;
use App\Models\QuotationStatusHistory;
use Illuminate\Validation\ValidationException;

class QuotationStatusService
{
    /**
     * Map of valid status transitions
     */
    protected array $validTransitions = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['approved', 'rejected', 'expired', 'cancelled'],
        'approved' => ['converted', 'cancelled'],
        'rejected' => ['cancelled', 'draft'],
        'expired' => ['draft', 'sent', 'cancelled'],
        'converted' => [],
        'cancelled' => [],
    ];

    /**
     * Transition a quotation to a new status.
     *
     * @param Quotation $quotation
     * @param string $newStatus
     * @param int|null $changedBy
     * @param string $source
     * @param string|null $notes
     * @return Quotation
     * @throws ValidationException
     */
    public function transition(
        Quotation $quotation,
        string $newStatus,
        ?int $changedBy = null,
        string $source = 'system',
        ?string $notes = null
    ): Quotation {
        $currentStatus = $quotation->status;

        if ($currentStatus === $newStatus) {
            return $quotation;
        }

        $allowed = $this->validTransitions[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition quotation from '{$currentStatus}' to '{$newStatus}'."]
            ]);
        }

        $oldStatus = $quotation->status;
        $quotation->status = $newStatus;

        if ($newStatus === 'approved') {
            $quotation->approved_at = now();
        } elseif ($newStatus === 'rejected') {
            $quotation->rejected_at = now();
        }

        $quotation->save();

        // Record history
        QuotationStatusHistory::create([
            'quotation_id' => $quotation->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $changedBy,
            'change_source' => $source,
            'notes' => $notes,
            'created_at' => now(),
        ]);

        ActivityLog::log(
            'Quotation Status Changed',
            "Quotation #{$quotation->quotation_number} status changed from {$oldStatus} to {$newStatus}.",
            [
                'quotation_id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'source' => $source,
            ]
        );

        return $quotation;
    }

    /**
     * Check if quotation is expired and auto-transition if appropriate.
     */
    public function checkAndMarkExpired(Quotation $quotation): bool
    {
        if (in_array($quotation->status, ['draft', 'sent']) && $quotation->valid_until) {
            if ($quotation->valid_until->endOfDay()->isPast()) {
                if ($quotation->status === 'sent') {
                    $this->transition($quotation, 'expired', null, 'system', 'Quotation validity period expired.');
                    return true;
                }
            }
        }
        return false;
    }
}

