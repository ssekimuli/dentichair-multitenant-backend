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
            $green = imagecolorallocate($img, 15, 118, 110); // #0F766E
            $white = imagecolorallocate($img, 255, 255, 255);
            $lightTeal = imagecolorallocate($img, 45, 212, 191);

            imagefill($img, 0, 0, $green);

            // Draw dental cross emblem
            imagefilledrectangle($img, 46, 25, 74, 95, $white);
            imagefilledrectangle($img, 25, 46, 95, 74, $white);

            // Inner accent
            imagefilledrectangle($img, 53, 33, 67, 87, $lightTeal);
            imagefilledrectangle($img, 33, 53, 87, 67, $lightTeal);

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

        // Calculate display dimensions for logo (height: 38pt, width: proportional up to 75pt)
        $logoHeight = 38.0;
        $aspectRatio = $logoInfo['height'] > 0 ? ($logoInfo['width'] / $logoInfo['height']) : 1.0;
        $logoWidth = round($logoHeight * $aspectRatio, 1);
        if ($logoWidth > 75.0) {
            $logoWidth = 75.0;
            $logoHeight = round($logoWidth / $aspectRatio, 1);
        }

        $stream = [];
        $stream[] = "q";

        // Render logo image on top left
        $logoY = 752;
        $stream[] = "q";
        $stream[] = sprintf("%.2f 0 0 %.2f 40 %.2f cm", $logoWidth, $logoHeight, $logoY);
        $stream[] = "/Im1 Do";
        $stream[] = "Q";

        // Header clinic info beside logo
        $textX = round(40 + $logoWidth + 12);
        // Clinic Name in Bold Green (#0F766E)
        $stream[] = "0.06 0.46 0.43 rg";
        $stream[] = "BT /F1 13.5 Tf {$textX} 784 Td (" . $clinicName . ") Tj ET";

        // Subtitle & Address in slate
        $stream[] = "0.39 0.45 0.55 rg";
        $subtitle = $this->sanitizePdfString($branding['subtitle'] ?? 'Dental Care Practice');
        if (!empty($subtitle)) {
            $stream[] = "BT /F1 8.5 Tf {$textX} 772 Td (" . $subtitle . ") Tj ET";
        }
        $stream[] = "BT /F1 8 Tf {$textX} 761 Td (" . $clinicAddress . ") Tj ET";
        $contactStr = "Email: " . $this->sanitizePdfString($branding['clinicEmail'] ?? '') . ($clinicPhone ? " • Phone: " . $clinicPhone : "");
        $stream[] = "BT /F1 8 Tf {$textX} 750 Td (" . $contactStr . ") Tj ET";

        // Top Right: Dark Pill Badge "TREATMENT QUOTATION"
        $stream[] = "0.06 0.09 0.16 rg"; // #0F172A
        $stream[] = "375 780 180 20 re f";
        $stream[] = "1 1 1 rg";
        $stream[] = "BT /F1 9.5 Tf 395 786 Td (TREATMENT QUOTATION) Tj ET";

        // Meta under badge
        $stream[] = "0.06 0.09 0.16 rg";
        $stream[] = "BT /F1 9 Tf 375 765 Td (Quote #: " . $quoteNumber . ") Tj ET";
        $stream[] = "0.39 0.45 0.55 rg";
        $stream[] = "BT /F1 8.5 Tf 375 753 Td (Date: " . $quoteDate . ") Tj ET";
        // Valid until in Green (#0F766E)
        $stream[] = "0.06 0.46 0.43 rg";
        $stream[] = "BT /F1 8.5 Tf 375 741 Td (Valid Until: " . $validUntil . ") Tj ET";

        // Solid Green Horizontal Divider Line
        $stream[] = "0.06 0.46 0.43 RG";
        $stream[] = "2.5 w";
        $stream[] = "40 732 m 555 732 l S";

        // Patient Details & Clinical Reference Box
        $stream[] = "0.97 0.98 0.99 rg"; // #F8FAFC
        $stream[] = "40 668 515 56 re f";
        $stream[] = "0.80 0.84 0.88 RG"; // #CBD5E1
        $stream[] = "1 w";
        $stream[] = "40 668 515 56 re S";

        // Vertical divider inside card
        $stream[] = "0.88 0.91 0.94 RG"; // #E2E8F0
        $stream[] = "0.8 w";
        $stream[] = "285 670 m 285 722 l S";

        // Left: Patient / Client Details
        $custLabel = $quotation->customer_type === 'patient' ? "PATIENT DETAILS:" : "CLIENT DETAILS:";
        $stream[] = "0.39 0.45 0.55 rg";
        $stream[] = "BT /F1 7.5 Tf 50 712 Td (" . $custLabel . ") Tj ET";
        $stream[] = "0.06 0.09 0.16 rg";
        $stream[] = "BT /F1 12 Tf 50 697 Td (" . $customerName . ") Tj ET";
        $stream[] = "0.20 0.25 0.35 rg";
        $customerPhone = $this->sanitizePdfString($quotation->customer_phone ?? '');
        $customerEmail = $this->sanitizePdfString($quotation->customer_email ?? '');
        $contactLine = $customerPhone ? "Phone: " . $customerPhone : ($customerEmail ? "Email: " . $customerEmail : "Contact on file");
        $stream[] = "BT /F1 8.5 Tf 50 684 Td (" . $contactLine . ") Tj ET";

        // Right: Clinical Reference
        $dentistName = $quotation->dentist ? "Dr. " . $this->sanitizePdfString($quotation->dentist->name) : "General Practice";
        $sourceType = strtoupper(str_replace('_', ' ', $quotation->source_type ?? 'MANUAL'));
        $stream[] = "0.39 0.45 0.55 rg";
        $stream[] = "BT /F1 7.5 Tf 295 712 Td (CLINICAL REFERENCE:) Tj ET";
        $stream[] = "0.20 0.25 0.35 rg";
        $stream[] = "BT /F1 8.5 Tf 295 699 Td (Attending: " . $dentistName . ") Tj ET";
        $stream[] = "BT /F1 8.5 Tf 295 687 Td (Source: " . $sourceType . ") Tj ET";
        // Status in Green
        $stream[] = "0.06 0.46 0.43 rg";
        $stream[] = "BT /F1 8.5 Tf 295 675 Td (Status: " . $status . ") Tj ET";

        // Section Title: PROPOSED SERVICES & PROCEDURES
        $stream[] = "0.28 0.33 0.41 rg";
        $stream[] = "BT /F1 8.5 Tf 40 648 Td (PROPOSED SERVICES & PROCEDURES) Tj ET";

        // Table Header
        $stream[] = "0.97 0.98 0.99 rg";
        $stream[] = "40 626 515 18 re f";
        $stream[] = "0.80 0.84 0.88 RG";
        $stream[] = "1 w";
        $stream[] = "40 626 515 18 re S";
        $stream[] = "0.06 0.09 0.16 rg";
        $stream[] = "BT /F1 8 Tf 46 631 Td (#) Tj ET";
        $stream[] = "BT /F1 8 Tf 68 631 Td (Procedure / Item Description) Tj ET";
        $stream[] = "BT /F1 8 Tf 275 631 Td (Tooth) Tj ET";
        $stream[] = "BT /F1 8 Tf 325 631 Td (Qty) Tj ET";
        $stream[] = "BT /F1 8 Tf 375 631 Td (Unit Price (" . $currency . ")) Tj ET";
        $stream[] = "BT /F1 8 Tf 465 631 Td (Total (" . $currency . ")) Tj ET";

        $y = 608;
        foreach ($quotation->items as $idx => $item) {
            if ($y < 140) {
                break;
            }
            $desc = $this->sanitizePdfString(substr($item->description, 0, 38));
            $tooth = $this->sanitizePdfString($item->tooth_number ?? '-');
            $qty = (string) $item->quantity;
            $unitPrice = number_format($item->unit_price, 2);
            $total = number_format($item->total, 2);

            // Light horizontal row border
            $stream[] = "0.88 0.91 0.94 RG";
            $stream[] = "0.5 w";
            $stream[] = "40 " . ($y - 4) . " m 555 " . ($y - 4) . " l S";

            $stream[] = "0.39 0.45 0.55 rg";
            $stream[] = "BT /F1 8 Tf 46 " . $y . " Td (" . ($idx + 1) . ") Tj ET";
            $stream[] = "0.06 0.09 0.16 rg";
            $stream[] = "BT /F1 8.5 Tf 68 " . $y . " Td (" . $desc . ") Tj ET";
            $stream[] = "0.20 0.25 0.35 rg";
            $stream[] = "BT /F1 8 Tf 280 " . $y . " Td (" . $tooth . ") Tj ET";
            $stream[] = "BT /F1 8 Tf 330 " . $y . " Td (" . $qty . ") Tj ET";
            $stream[] = "BT /F1 8 Tf 385 " . $y . " Td (" . $unitPrice . ") Tj ET";
            // Total in bold dark
            $stream[] = "0.06 0.09 0.16 rg";
            $stream[] = "BT /F1 8.5 Tf 475 " . $y . " Td (" . $total . ") Tj ET";

            $y -= 16;
        }

        // Bottom Grid: Left (Notes & Terms), Right (Totals Box)
        $subtotal = number_format($quotation->subtotal, 2);
        $grandTotal = $currency . " " . number_format($quotation->total, 2);

        // Notes & Terms on Left
        $notesText = $this->sanitizePdfString($quotation->notes ? substr($quotation->notes, 0, 80) : 'Treatment plan estimate');
        $stream[] = "0.28 0.33 0.41 rg";
        $stream[] = "BT /F1 8 Tf 40 " . ($y - 8) . " Td (Notes / Clinical Recommendations:) Tj ET";
        $stream[] = "0.20 0.25 0.35 rg";
        $stream[] = "BT /F1 8 Tf 40 " . ($y - 19) . " Td (" . $notesText . ") Tj ET";

        $stream[] = "0.28 0.33 0.41 rg";
        $stream[] = "BT /F1 8 Tf 40 " . ($y - 34) . " Td (Terms & Conditions:) Tj ET";
        $stream[] = "0.39 0.45 0.55 rg";
        $stream[] = "BT /F1 7.5 Tf 40 " . ($y - 45) . " Td (1. Quotation is valid for 14 days from date of issue.) Tj ET";
        $stream[] = "BT /F1 7.5 Tf 40 " . ($y - 55) . " Td (2. Payment terms as agreed upon consultation.) Tj ET";

        // Totals Box on Right
        $stream[] = "0.98 0.99 0.98 rg"; // #FAFCFB
        $stream[] = "340 " . ($y - 62) . " 215 58 re f";
        $stream[] = "0.80 0.84 0.88 RG"; // #CBD5E1
        $stream[] = "1 w";
        $stream[] = "340 " . ($y - 62) . " 215 58 re S";

        $stream[] = "0.39 0.45 0.55 rg";
        $stream[] = "BT /F1 8.5 Tf 352 " . ($y - 20) . " Td (Subtotal:) Tj ET";
        $stream[] = "0.06 0.09 0.16 rg";
        $stream[] = "BT /F1 8.5 Tf 460 " . ($y - 20) . " Td (" . $currency . " " . $subtotal . ") Tj ET";

        // Divider in totals box
        $stream[] = "0.88 0.91 0.94 RG";
        $stream[] = "0.8 w";
        $stream[] = "348 " . ($y - 28) . " m 547 " . ($y - 28) . " l S";

        // TOTAL DUE in bold green (#0F766E)
        $stream[] = "0.06 0.09 0.16 rg";
        $stream[] = "BT /F1 10 Tf 352 " . ($y - 46) . " Td (TOTAL DUE:) Tj ET";
        $stream[] = "0.06 0.46 0.43 rg"; // #0F766E Green!
        $stream[] = "BT /F1 12 Tf 435 " . ($y - 46) . " Td (" . $grandTotal . ") Tj ET";

        // Signatures at bottom of page
        $stream[] = "0.58 0.64 0.72 RG"; // line
        $stream[] = "1 w";
        $stream[] = "40 70 m 230 70 l S";
        $stream[] = "365 70 m 555 70 l S";

        $stream[] = "0.39 0.45 0.55 rg";
        $stream[] = "BT /F1 8 Tf 40 58 Td (Authorized Clinician Signature) Tj ET";
        $stream[] = "BT /F1 8 Tf 365 58 Td (Patient / Client Acceptance Signature) Tj ET";
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
