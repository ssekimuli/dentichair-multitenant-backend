<?php

namespace App\Services\Quotation;

use App\Models\BranchSetting;
use App\Models\GlobalSetting;
use App\Models\Quotation;
use Illuminate\Support\Str;

class QuotationNumberService
{
    /**
     * Generate a unique quotation number.
     * Format: QUO-{YYYYMM}-{BRANCH_ID}-{RANDOM}
     */
    public function generate(int $branchId): string
    {
        $prefix = 'QUO-';
        try {
            $setting = GlobalSetting::getSettings();
            if (!empty($setting->receipt_prefix)) {
                $prefix = 'QUO-';
            }
        } catch (\Throwable $e) {}

        $datePart = now()->format('Ym');

        do {
            $random = strtoupper(Str::random(6));
            $number = "{$prefix}{$datePart}-{$branchId}-{$random}";
        } while (Quotation::where('quotation_number', $number)->exists());

        return $number;
    }
}

