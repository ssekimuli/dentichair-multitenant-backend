<?php

namespace App\Services;

use App\Models\Patient;

/**
 * Cascades the deletion of a patient and all records that reference it.
 *
 * Centralized here because patient deletion is triggered from more than one
 * place (deleting a patient directly, deleting a branch), and those places
 * had drifted out of sync with which related tables actually need cleanup.
 */
class PatientDeletionService
{
    public function delete(Patient $patient): void
    {
        // Invoices soft-delete, so an already-trashed invoice would otherwise
        // be invisible to the default query while still blocking the FK.
        foreach ($patient->invoices()->withTrashed()->get() as $invoice) {
            $invoice->treatments()->delete();
            $invoice->otherCharges()->delete();
            $invoice->transactions()->delete();
            $invoice->forceDelete();
        }

        $patient->dentalRecords()->delete();
        $patient->treatmentPlans()->delete();
        $patient->images()->delete();
        $patient->periodontalRecords()->delete();
        $patient->softTissueRecords()->delete();
        $patient->orthodonticRecords()->delete();
        $patient->endodonticRecords()->delete();
        $patient->appointments()->delete();

        $patient->delete();
    }
}
