<?php

namespace App\Services\Quotation;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GlobalSetting;
use App\Models\Quotation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class QuotationPdfService
{
    /**
     * Get branding details for the quotation's branch.
     */
    public function getBranding(Quotation $quotation): array
    {
        $branch = $quotation->branch ?? Branch::find($quotation->branch_id);
        $branchSetting = $branch?->setting;
        $business = Business::first();
        $globalSetting = class_exists(GlobalSetting::class) ? GlobalSetting::getSettings() : null;

        $clinicName = $globalSetting?->clinic_name
            ?: ($branchSetting?->clinic_name
            ?: ($business?->name
            ?: ($branch?->name ?: config('app.name', 'DentiChair Dental Practice'))));

        $clinicAddress = $globalSetting?->address
            ?: ($branch?->address ?: 'Clinic Practice Address');

        $clinicPhone = $globalSetting?->phone
            ?: ($branch?->phone ?: '');

        $clinicEmail = $globalSetting?->email
            ?: ($business?->business_email
            ?: ($branch?->email ?: config('mail.from.address', 'info@dentichair.com')));

        $clinicLogo = $branch?->logo
            ?: ($branchSetting?->logo
            ?: ($globalSetting?->logo
            ?: ($business?->logo ?: null)));

        $subtitle = $globalSetting?->subtitle ?: ($branchSetting?->subtitle ?: '');
        $receiptFooter = $globalSetting?->receipt_footer ?: ($branchSetting?->receipt_footer ?: 'Thank you for choosing our facility for your healthcare needs.');

        return [
            'clinicName' => $clinicName,
            'subtitle' => $subtitle,
            'clinicAddress' => $clinicAddress,
            'clinicPhone' => $clinicPhone,
            'clinicEmail' => $clinicEmail,
            'clinicLogo' => $clinicLogo,
            'receiptFooter' => $receiptFooter,
        ];
    }

    /**
     * Render the Blade HTML view.
     */
    public function renderHtml(Quotation $quotation): string
    {
        $quotation->load(['items', 'patient', 'labClient', 'dentist', 'labOrder', 'branch']);
        $branding = $this->getBranding($quotation);

        return view('quotations.pdf', array_merge(['quotation' => $quotation], $branding))->render();
    }

    /**
     * Resolve logo image to raw JPEG bytes and dimensions.
     */
    public function resolveLogoImage(?string $logoSource): ?array
    {
        if (empty($logoSource)) {
            return null;
        }

        $rawBytes = null;

        // 1. Data URI
        if (str_starts_with($logoSource, 'data:image')) {
            $parts = explode(',', $logoSource, 2);
            if (isset($parts[1])) {
                $rawBytes = base64_decode($parts[1]);
            }
        }

        // 2. Local File Paths
        if (!$rawBytes) {
            $candidates = [
                $logoSource,
                public_path(ltrim($logoSource, '/\\')),
                storage_path('app/public/' . ltrim(str_replace(['/storage/', 'storage/'], '', $logoSource), '/\\')),
                storage_path('app/' . ltrim($logoSource, '/\\')),
            ];

            foreach ($candidates as $candidate) {
                if (@file_exists($candidate) && @is_file($candidate)) {
                    $rawBytes = @file_get_contents($candidate);
                    if ($rawBytes) {
                        break;
                    }
                }
            }
        }

        // 3. Remote HTTP/HTTPS URL
        if (!$rawBytes && (str_starts_with($logoSource, 'http://') || str_starts_with($logoSource, 'https://'))) {
            try {
                $resp = Http::timeout(3)->get($logoSource);
                if ($resp->successful()) {
                    $rawBytes = $resp->body();
                }
            } catch (\Throwable $e) {}
        }

        if (!$rawBytes) {
            return null;
        }

        // 4. Convert to JPEG using GD
        if (extension_loaded('gd')) {
            try {
                $img = @imagecreatefromstring($rawBytes);
                if ($img !== false) {
                    $w = imagesx($img);
                    $h = imagesy($img);

                    if ($w > 0 && $h > 0) {
                        $tc = imagecreatetruecolor($w, $h);
                        $white = imagecolorallocate($tc, 255, 255, 255);
                        imagefill($tc, 0, 0, $white);
                        imagecopy($tc, $img, 0, 0, 0, 0, $w, $h);

                        ob_start();
                        imagejpeg($tc, null, 90);
                        $jpegBytes = ob_get_clean();

                        imagedestroy($img);
                        imagedestroy($tc);

                        return [
                            'width' => $w,
                            'height' => $h,
                            'jpeg' => $jpegBytes,
                        ];
                    }
                }
            } catch (\Throwable $e) {}
        }

        return null;
    }

    /**
     * Generate default practice emblem logo if no custom logo is uploaded.
     */
    public function generateDefaultLogo(): array
    {
        $w = 120;
        $h = 120;

        if (extension_loaded('gd')) {
            $img = imagecreatetruecolor($w, $h);
            $blue = imagecolorallocate($img, 30, 64, 175); // #1e40af
            $white = imagecolorallocate($img, 255, 255, 255);
            $cyan = imagecolorallocate($img, 56, 189, 248);

            imagefill($img, 0, 0, $blue);

            // Draw dental cross emblem
            imagefilledrectangle($img, 46, 25, 74, 95, $white);
            imagefilledrectangle($img, 25, 46, 95, 74, $white);

            // Inner accent
            imagefilledrectangle($img, 53, 33, 67, 87, $cyan);
            imagefilledrectangle($img, 33, 53, 87, 67, $cyan);

            ob_start();
            imagejpeg($img, null, 90);
            $jpegBytes = ob_get_clean();
            imagedestroy($img);

            return [
                'width' => $w,
                'height' => $h,
                'jpeg' => $jpegBytes,
            ];
        }

        $tinyJpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        return [
            'width' => 1,
            'height' => 1,
            'jpeg' => $tinyJpeg,
        ];
    }

    /**
     * Generate standard PDF binary stream (%PDF-1.4) including logo.
     */
    public function generatePdf(Quotation $quotation): string
    {
        $quotation->load(['items', 'patient', 'labClient', 'dentist', 'labOrder', 'branch']);
        $branding = $this->getBranding($quotation);

        $clinicName = $this->sanitizePdfString($branding['clinicName']);
        $clinicAddress = $this->sanitizePdfString($branding['clinicAddress']);
        $clinicPhone = $this->sanitizePdfString($branding['clinicPhone']);
        $quoteNumber = $this->sanitizePdfString($quotation->quotation_number);
        $customerName = $this->sanitizePdfString($quotation->customer_name ?? 'Customer');
        $quoteDate = $quotation->quotation_date ? $quotation->quotation_date->format('Y-m-d') : '';
        $validUntil = $quotation->valid_until ? $quotation->valid_until->format('Y-m-d') : '';
        $currency = $this->sanitizePdfString($quotation->currency ?? 'USD');
        $status = strtoupper($quotation->status);

        // Resolve logo (either from upload or default practice emblem)
        $logoInfo = $this->resolveLogoImage($branding['clinicLogo']) ?: $this->generateDefaultLogo();

        // Calculate display dimensions for logo (height: 36pt, width: proportional up to 85pt)
        $logoHeight = 36.0;
        $aspectRatio = $logoInfo['height'] > 0 ? ($logoInfo['width'] / $logoInfo['height']) : 1.0;
        $logoWidth = round($logoHeight * $aspectRatio, 1);
        if ($logoWidth > 85.0) {
            $logoWidth = 85.0;
            $logoHeight = round($logoWidth / $aspectRatio, 1);
        }

        $stream = [];
        $stream[] = "q";

        // Header blue banner
        $stream[] = "0.12 0.25 0.69 rg"; // #1e40af
        $stream[] = "40 758 515 44 re f";

        // Render logo image
        $logoY = 762;
        $stream[] = "q";
        $stream[] = sprintf("%.2f 0 0 %.2f 46 %.2f cm", $logoWidth, $logoHeight, $logoY);
        $stream[] = "/Im1 Do";
        $stream[] = "Q";

        // Header text beside logo
        $textX = round(46 + $logoWidth + 10);
        $stream[] = "1 1 1 rg";
        $stream[] = "BT /F1 15 Tf {$textX} 776 Td (" . $clinicName . " - QUOTATION) Tj ET";
        $stream[] = "0 0 0 rg";

        // Metadata block
        $stream[] = "BT /F1 10 Tf 50 735 Td (Quote #: " . $quoteNumber . ") Tj ET";
        $stream[] = "BT /F1 10 Tf 50 720 Td (Status: " . $status . ") Tj ET";
        $stream[] = "BT /F1 10 Tf 50 705 Td (Date: " . $quoteDate . " | Valid Until: " . $validUntil . ") Tj ET";
        $stream[] = "BT /F1 10 Tf 320 735 Td (Customer: " . $customerName . ") Tj ET";
        if ($quotation->dentist) {
            $stream[] = "BT /F1 10 Tf 320 720 Td (Dentist: Dr. " . $this->sanitizePdfString($quotation->dentist->name) . ") Tj ET";
        }

        // Table Header
        $stream[] = "0.95 0.96 0.98 rg";
        $stream[] = "40 670 515 20 re f";
        $stream[] = "0 0 0 rg";
        $stream[] = "BT /F1 9 Tf 50 676 Td (Item Description) Tj ET";
        $stream[] = "BT /F1 9 Tf 280 676 Td (Tooth) Tj ET";
        $stream[] = "BT /F1 9 Tf 330 676 Td (Qty) Tj ET";
        $stream[] = "BT /F1 9 Tf 380 676 Td (Unit Price) Tj ET";
        $stream[] = "BT /F1 9 Tf 470 676 Td (Total (" . $currency . ")) Tj ET";

        $y = 650;
        foreach ($quotation->items as $idx => $item) {
            if ($y < 120) {
                break;
            }
            $desc = $this->sanitizePdfString(substr($item->description, 0, 40));
            $tooth = $this->sanitizePdfString($item->tooth_number ?? '-');
            $qty = (string) $item->quantity;
            $unitPrice = number_format($item->unit_price, 2);
            $total = number_format($item->total, 2);

            $stream[] = "BT /F1 9 Tf 50 " . $y . " Td (" . $desc . ") Tj ET";
            $stream[] = "BT /F1 9 Tf 280 " . $y . " Td (" . $tooth . ") Tj ET";
            $stream[] = "BT /F1 9 Tf 330 " . $y . " Td (" . $qty . ") Tj ET";
            $stream[] = "BT /F1 9 Tf 380 " . $y . " Td (" . $unitPrice . ") Tj ET";
            $stream[] = "BT /F1 9 Tf 470 " . $y . " Td (" . $total . ") Tj ET";

            $y -= 18;
        }

        // Totals
        $y -= 10;
        $stream[] = "0.8 0.8 0.8 rg";
        $stream[] = "40 " . ($y + 12) . " 515 1 re f";
        $stream[] = "0 0 0 rg";

        $subtotal = number_format($quotation->subtotal, 2) . " " . $currency;
        $discount = number_format($quotation->discount_amount, 2) . " " . $currency;
        $tax = number_format($quotation->tax_amount, 2) . " " . $currency;
        $grandTotal = number_format($quotation->total, 2) . " " . $currency;

        $stream[] = "BT /F1 10 Tf 350 " . $y . " Td (Subtotal: " . $subtotal . ") Tj ET";
        $y -= 16;
        if ($quotation->discount_amount > 0) {
            $stream[] = "BT /F1 10 Tf 350 " . $y . " Td (Discount: -" . $discount . ") Tj ET";
            $y -= 16;
        }
        if ($quotation->tax_amount > 0) {
            $stream[] = "BT /F1 10 Tf 350 " . $y . " Td (Tax: +" . $tax . ") Tj ET";
            $y -= 16;
        }
        $stream[] = "BT /F1 12 Tf 350 " . $y . " Td (TOTAL: " . $grandTotal . ") Tj ET";

        // Footer note
        $stream[] = "BT /F1 8 Tf 50 50 Td (This quotation is generated by " . $clinicName . " and is valid until " . $validUntil . ".) Tj ET";
        $stream[] = "Q";

        $content = implode("\n", $stream);
        $contentLength = strlen($content);

        // Build valid PDF document structure with Image XObject
        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> /XObject << /Im1 6 0 R >> >> >>";
        $objects[4] = "<< /Length " . $contentLength . " >>\nstream\n" . $content . "\nendstream";
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[6] = "<< /Type /XObject /Subtype /Image /Width " . (int)$logoInfo['width'] . " /Height " . (int)$logoInfo['height'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($logoInfo['jpeg']) . " >>\nstream\n" . $logoInfo['jpeg'] . "\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $obj . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= "0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n";
        $pdf .= "<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    /**
     * Produce an HTTP Response for the quotation (PDF stream or HTML preview).
     */
    public function response(Quotation $quotation, string $format = 'pdf'): Response
    {
        if ($format === 'html') {
            return response($this->renderHtml($quotation), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        $pdfBinary = $this->generatePdf($quotation);
        $filename = "quotation-{$quotation->quotation_number}.pdf";

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Content-Length' => strlen($pdfBinary),
        ]);
    }

    private function sanitizePdfString(string $str): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', ' ', $str);
        $clean = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
        return trim($clean);
    }
}
