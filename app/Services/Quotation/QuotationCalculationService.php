<?php

namespace App\Services\Quotation;

class QuotationCalculationService
{
    /**
     * Calculate line item financial breakdown.
     *
     * @param array $itemData
     * @return array
     */
    public function calculateItem(array $itemData): array
    {
        $quantity = max(1, (int) ($itemData['quantity'] ?? 1));
        $unitPrice = max(0.00, round((float) ($itemData['unit_price'] ?? 0.00), 2));
        $itemSubtotal = round($quantity * $unitPrice, 2);

        $discountType = $itemData['discount_type'] ?? 'none';
        $rawDiscountAmount = max(0.00, (float) ($itemData['discount_amount'] ?? 0.00));
        $discountAmount = 0.00;

        if ($discountType === 'percentage') {
            $percentage = min(100.00, $rawDiscountAmount);
            $discountAmount = round(($itemSubtotal * $percentage) / 100, 2);
        } elseif ($discountType === 'fixed') {
            $discountAmount = min($itemSubtotal, round($rawDiscountAmount, 2));
        }

        $baseAfterDiscount = max(0.00, round($itemSubtotal - $discountAmount, 2));

        $taxRate = max(0.00, min(100.00, (float) ($itemData['tax_rate'] ?? 0.00)));
        $taxAmount = 0.00;
        if ($taxRate > 0) {
            $taxAmount = round(($baseAfterDiscount * $taxRate) / 100, 2);
        }

        $lineTotal = round($baseAfterDiscount + $taxAmount, 2);

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $itemSubtotal,
            'discount_type' => in_array($discountType, ['percentage', 'fixed']) ? $discountType : 'none',
            'discount_amount' => $discountAmount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $lineTotal,
        ];
    }

    /**
     * Calculate overall quotation financial summary from items and overall adjustments.
     *
     * @param array $calculatedItems
     * @param string|null $overallDiscountType
     * @param float $overallDiscountValue
     * @param float $overallTaxRate
     * @return array
     */
    public function calculateQuotation(
        array $calculatedItems,
        ?string $overallDiscountType = 'none',
        float $overallDiscountValue = 0.00,
        float $overallTaxRate = 0.00
    ): array {
        // Subtotal is sum of item subtotals (or sum of item totals before overall adjustments)
        $itemsSubtotal = 0.00;
        $itemsDiscountTotal = 0.00;
        $itemsTaxTotal = 0.00;
        $itemsNetTotal = 0.00;

        foreach ($calculatedItems as $item) {
            $itemsSubtotal += (float) ($item['subtotal'] ?? ($item['quantity'] * $item['unit_price']));
            $itemsDiscountTotal += (float) ($item['discount_amount'] ?? 0);
            $itemsTaxTotal += (float) ($item['tax_amount'] ?? 0);
            $itemsNetTotal += (float) ($item['total'] ?? 0);
        }

        $itemsSubtotal = round($itemsSubtotal, 2);
        $itemsDiscountTotal = round($itemsDiscountTotal, 2);
        $itemsTaxTotal = round($itemsTaxTotal, 2);

        // Overall quotation-level discount
        $overallDiscountAmount = 0.00;
        $rawDiscountValue = max(0.00, $overallDiscountValue);

        if ($overallDiscountType === 'percentage') {
            $pct = min(100.00, $rawDiscountValue);
            $overallDiscountAmount = round(($itemsNetTotal * $pct) / 100, 2);
        } elseif ($overallDiscountType === 'fixed') {
            $overallDiscountAmount = min($itemsNetTotal, round($rawDiscountValue, 2));
        }

        $subtotalAfterOverallDiscount = max(0.00, round($itemsNetTotal - $overallDiscountAmount, 2));

        // Overall quotation-level tax
        $quotationTaxRate = max(0.00, min(100.00, $overallTaxRate));
        $quotationTaxAmount = 0.00;
        if ($quotationTaxRate > 0) {
            $quotationTaxAmount = round(($subtotalAfterOverallDiscount * $quotationTaxRate) / 100, 2);
        }

        $grandTotal = round($subtotalAfterOverallDiscount + $quotationTaxAmount, 2);

        return [
            'subtotal' => $itemsSubtotal,
            'discount_type' => in_array($overallDiscountType, ['percentage', 'fixed']) ? $overallDiscountType : 'none',
            'discount_amount' => round($itemsDiscountTotal + $overallDiscountAmount, 2),
            'tax_rate' => $quotationTaxRate,
            'tax_amount' => round($itemsTaxTotal + $quotationTaxAmount, 2),
            'total' => max(0.00, $grandTotal),
        ];
    }
}

