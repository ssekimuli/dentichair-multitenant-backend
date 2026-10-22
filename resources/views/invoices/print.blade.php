<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Invoice #{{ $invoice->id }} - {{ $patient->first_name ?? $invoice->patient_name ?? 'Patient' }} {{ $patient->last_name ?? '' }}</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #0f172a;
            background: #f8fafc;
            line-height: 1.5;
            padding: 24px;
            font-size: 13px;
        }

        .paper {
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
            padding: 40px 48px;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            position: relative;
        }

        /* ACTIONS BAR (Hidden on Print) */
        .actions-bar {
            max-width: 820px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #1e293b;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-primary {
            background: #0f766e;
            color: #ffffff;
            border-color: #0f766e;
        }

        .btn-primary:hover {
            background: #115e59;
        }

        /* CLINIC HEADER */
        .clinic-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 16px;
            border-bottom: 2px solid #0f766e;
            margin-bottom: 20px;
        }

        .clinic-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }

        .clinic-logo-img {
            max-height: 58px;
            max-width: 140px;
            object-fit: contain;
            display: block;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .clinic-brand h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0f766e;
            letter-spacing: -0.3px;
            text-transform: uppercase;
        }

        .clinic-brand .tagline {
            color: #334155;
            font-size: 11px;
            font-weight: 600;
            margin-top: 2px;
        }

        .clinic-brand .contact-info {
            color: #64748b;
            font-size: 11px;
            margin-top: 3px;
        }

        .invoice-badge-box {
            text-align: right;
        }

        .invoice-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.5px;
        }

        .invoice-ref {
            font-family: monospace;
            font-size: 12.5px;
            font-weight: 700;
            color: #0f766e;
            margin-top: 2px;
        }

        .invoice-date {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 2px;
        }

        .status-pill {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 9999px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .status-paid {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .status-unpaid {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* PATIENT & BILLING CARD */
        .billing-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 24px;
        }

        .meta-col-title {
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .patient-name {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .meta-text {
            font-size: 11.5px;
            color: #475569;
            margin-top: 2px;
        }

        /* ITEMS TABLE */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .items-table th {
            background: #0f766e;
            color: #ffffff;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 12px;
            text-align: left;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .items-table th.text-center { text-align: center; }
        .items-table th.text-right { text-align: right; }

        .items-table td {
            padding: 9px 12px;
            font-size: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #1e293b;
        }

        .items-table td.text-center { text-align: center; }
        .items-table td.text-right { text-align: right; }

        .items-table tr:nth-child(even) td {
            background: #f8fafc;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* TOTALS SUMMARY */
        .summary-wrapper {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }

        .summary-box {
            width: 320px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 12px;
            color: #334155;
        }

        .summary-row.bold {
            font-weight: 700;
            color: #0f172a;
        }

        .summary-row.large {
            font-size: 14px;
            font-weight: 800;
            padding-top: 8px;
            border-top: 2px solid #0f766e;
            margin-top: 4px;
        }

        .text-green { color: #15803d; }
        .text-red { color: #b91c1c; }

        /* TRANSACTIONS SECTION */
        .section-heading {
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f766e;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-heading::after {
            content: "";
            flex: 1;
            border-bottom: 1px dashed #cbd5e1;
        }

        .txn-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            font-size: 11.5px;
        }

        .txn-table th {
            background: #f1f5f9;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 6px 10px;
            text-align: left;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .txn-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }

        /* SIGNATURES & STAMP AREA */
        .signatures-area {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 36px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            page-break-inside: avoid;
        }

        .signature-box {
            text-align: center;
            width: 180px;
        }

        .signature-line {
            border-bottom: 1px solid #475569;
            height: 38px;
            margin-bottom: 6px;
        }

        .signature-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
        }

        .stamp-box {
            width: 130px;
            height: 65px;
            border: 1px dashed #94a3b8;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* FOOTER NOTE */
        .invoice-footer {
            margin-top: 24px;
            text-align: center;
            font-size: 11px;
            color: #64748b;
            border-top: 1px dashed #cbd5e1;
            padding-top: 12px;
        }

        /* WATERMARK (LOGO + CLINIC NAME) */
        .print-watermark {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 0;
            user-select: none;
            -webkit-user-select: none;
        }

        .print-watermark-logo-box {
            opacity: 0.055;
            margin-bottom: 14px;
            display: flex;
            justify-content: center;
            align-items: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .print-watermark-logo {
            max-width: 280px;
            max-height: 280px;
            object-fit: contain;
            filter: grayscale(100%);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .print-watermark-text {
            font-size: clamp(24px, 3.5vw, 42px);
            font-weight: 800;
            color: #0f172a;
            opacity: 0.05;
            text-transform: uppercase;
            letter-spacing: 6px;
            transform: rotate(-18deg);
            text-align: center;
            line-height: 1.25;
            max-width: 85%;
            word-break: break-word;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Relative container for content above watermark */
        .content-layer {
            position: relative;
            z-index: 1;
        }

        /* PRINT MEDIA STYLES */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                font-size: 12px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .actions-bar {
                display: none !important;
            }

            .paper {
                box-shadow: none !important;
                padding: 10mm 12mm !important;
                max-width: 100% !important;
                margin: 0 !important;
            }

            .signatures-area {
                page-break-inside: avoid;
            }

            .print-watermark {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                height: 100% !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                pointer-events: none !important;
                z-index: 0 !important;
            }

            .print-watermark-logo-box {
                opacity: 0.06 !important;
                margin-bottom: 14px !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .print-watermark-logo {
                max-width: 280px !important;
                max-height: 280px !important;
                object-fit: contain !important;
                filter: grayscale(100%) !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .print-watermark-text {
                font-size: clamp(24px, 4vw, 46px) !important;
                font-weight: 900 !important;
                color: #0f172a !important;
                opacity: 0.05 !important;
                text-transform: uppercase !important;
                letter-spacing: 6px !important;
                transform: rotate(-18deg) !important;
                text-align: center !important;
                line-height: 1.25 !important;
                max-width: 85% !important;
                word-break: break-word !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>

    <!-- ACTIONS (Hidden on print) -->
    <div class="actions-bar">
        <button onclick="window.history.back()" class="btn">
            &larr; Back
        </button>
        <button onclick="window.print()" class="btn btn-primary">
            🖨️ Print Tax Invoice / Download PDF
        </button>
    </div>

    <!-- MAIN INVOICE PAPER -->
    <div class="paper">

        <!-- FACILITY WATERMARK (LOGO + CLINIC NAME) -->
        <div class="print-watermark" aria-hidden="true">
            @if(!empty($clinicLogo))
                <div class="print-watermark-logo-box">
                    <img src="{{ $clinicLogo }}" alt="" class="print-watermark-logo">
                </div>
            @endif
            @if(!empty($clinicName))
                <div class="print-watermark-text">{{ $clinicName }}</div>
            @endif
        </div>

        <div class="content-layer">

            <!-- CLINIC HEADER LETTERHEAD -->
            <div class="clinic-header">
                <div class="clinic-header-left">
                    @if(!empty($clinicLogo))
                        <img src="{{ $clinicLogo }}" alt="{{ $clinicName }}" class="clinic-logo-img">
                    @endif
                    <div class="clinic-brand">
                        <h1>{{ $clinicName }}</h1>
                        @if(!empty($clinicDetails))
                            <p class="tagline">{{ $clinicDetails }}</p>
                        @endif
                        <p class="contact-info">
                            @if(!empty($clinicAddress)) {{ $clinicAddress }} @endif
                            @if(!empty($clinicPhone)) &bull; Tel: {{ $clinicPhone }} @endif
                            @if(!empty($clinicEmail)) &bull; {{ $clinicEmail }} @endif
                        </p>
                    </div>
                </div>

                <div class="invoice-badge-box">
                    <div class="invoice-title">TAX INVOICE</div>
                    <div class="invoice-ref">#{{ $invoice->id }}</div>
                    @if(!empty($invoice->receipt_number))
                        <div class="invoice-date">Receipt #: <strong>{{ $invoice->receipt_number }}</strong></div>
                    @endif
                    <div class="invoice-date">Date: {{ $invoice->issue_date?->format('d M, Y') ?? now()->format('d M, Y') }}</div>
                    @php
                        $isPaid = strtolower($invoice->status ?? '') === 'paid';
                    @endphp
                    <div>
                        <span class="status-pill {{ $isPaid ? 'status-paid' : 'status-unpaid' }}">
                            {{ strtoupper($invoice->status ?? 'UNPAID') }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- PATIENT & BILLING DETAILS -->
            <div class="billing-meta-grid">
                <div>
                    <div class="meta-col-title">Billed To</div>
                    <div class="patient-name">
                        {{ $patient->first_name ?? $invoice->patient_name ?? 'Patient' }} {{ $patient->last_name ?? '' }}
                    </div>
                    @if(!empty($patient->patient_id) || !empty($invoice->patient_id))
                        <div class="meta-text">MRN / ID: #{{ $patient->patient_id ?? $invoice->patient_id }}</div>
                    @endif
                    @if(!empty($patient->phone))
                        <div class="meta-text">Phone: {{ $patient->phone }}</div>
                    @endif
                    @if(!empty($patient->email))
                        <div class="meta-text">Email: {{ $patient->email }}</div>
                    @endif
                    @if(!empty($patient->address))
                        <div class="meta-text">Address: {{ $patient->address }}</div>
                    @endif
                </div>

                <div style="text-align: right;">
                    <div class="meta-col-title">Billing Particulars</div>
                    @if(!empty($branchName))
                        <div class="meta-text">Branch: <strong>{{ $branchName }}</strong></div>
                    @endif
                    @if(!empty($invoice->category))
                        <div class="meta-text">Category: <strong>{{ $invoice->category }}</strong></div>
                    @endif
                    @if(!empty($invoice->payment_method))
                        <div class="meta-text">Payment Method: <strong>{{ $invoice->payment_method }}</strong></div>
                    @endif
                    @if(!empty($invoice->due_date))
                        <div class="meta-text">Due Date: <strong>{{ $invoice->due_date?->format('d M, Y') }}</strong></div>
                    @endif
                </div>
            </div>

            <!-- ITEMIZED CHARGES TABLE -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 40px;" class="text-center">#</th>
                        <th>Item / Description</th>
                        <th class="text-center" style="width: 80px;">Qty</th>
                        <th class="text-right" style="width: 140px;">Unit Price (UGX)</th>
                        <th class="text-right" style="width: 140px;">Total (UGX)</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $itemIndex = 1;
                        $hasItems = false;
                    @endphp

                    {{-- Treatments / Procedures --}}
                    @if($invoice->treatments && $invoice->treatments->isNotEmpty())
                        @php $hasItems = true; @endphp
                        @foreach($invoice->treatments as $treatment)
                            @php
                                $cost = (float)($treatment->cost ?? 0);
                                $qty = (int)($treatment->quantity ?? 1);
                                if ($qty <= 0) $qty = 1;
                                $unitPrice = $cost;
                                $lineTotal = $cost * $qty;
                            @endphp
                            <tr>
                                <td class="text-center">{{ $itemIndex++ }}</td>
                                <td>
                                    <strong>{{ $treatment->procedure ?? 'Dental Procedure' }}</strong>
                                    @if(!empty($treatment->tooth))
                                        <span style="font-size: 11px; color: #64748b;">(Tooth #{{ $treatment->tooth }})</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $qty }}</td>
                                <td class="text-right">{{ number_format($unitPrice, 0) }}</td>
                                <td class="text-right font-weight-bold">{{ number_format($lineTotal, 0) }}</td>
                            </tr>
                        @endforeach
                    @endif

                    {{-- Medicines / Prescriptions --}}
                    @if(!empty($invoice->medicines) && is_array($invoice->medicines))
                        @php $hasItems = true; @endphp
                        @foreach($invoice->medicines as $med)
                            @php
                                $medName = $med['medicine']['name'] ?? $med['name'] ?? 'Prescribed Medicine';
                                $qty = (int)($med['quantity'] ?? 1);
                                if ($qty <= 0) $qty = 1;
                                $unitPrice = (float)($med['unit']['price'] ?? $med['cost'] ?? $med['price'] ?? 0);
                                $lineTotal = $unitPrice * $qty;
                            @endphp
                            <tr>
                                <td class="text-center">{{ $itemIndex++ }}</td>
                                <td>
                                    <strong>{{ $medName }}</strong>
                                    @if(!empty($med['unit']['unit_name']))
                                        <span style="font-size: 11px; color: #64748b;">({{ $med['unit']['unit_name'] }})</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $qty }}</td>
                                <td class="text-right">{{ number_format($unitPrice, 0) }}</td>
                                <td class="text-right font-weight-bold">{{ number_format($lineTotal, 0) }}</td>
                            </tr>
                        @endforeach
                    @endif

                    {{-- Other Charges --}}
                    @if($invoice->otherCharges && $invoice->otherCharges->isNotEmpty())
                        @php $hasItems = true; @endphp
                        @foreach($invoice->otherCharges as $charge)
                            @php
                                $amt = (float)($charge->amount ?? 0);
                            @endphp
                            <tr>
                                <td class="text-center">{{ $itemIndex++ }}</td>
                                <td>
                                    <strong>{{ $charge->description ?? 'Clinical Surcharge' }}</strong>
                                </td>
                                <td class="text-center">1</td>
                                <td class="text-right">{{ number_format($amt, 0) }}</td>
                                <td class="text-right font-weight-bold">{{ number_format($amt, 0) }}</td>
                            </tr>
                        @endforeach
                    @endif

                    {{-- Laboratory Order if billed with invoice --}}
                    @if($invoice->laboratoryOrder)
                        @php
                            $hasItems = true;
                            $lab = $invoice->laboratoryOrder;
                            $cost = (float)($lab->cost ?? 0);
                        @endphp
                        <tr>
                            <td class="text-center">{{ $itemIndex++ }}</td>
                            <td>
                                <strong>Laboratory Prosthesis / {{ $lab->item ?? 'Dental Restoration' }}</strong>
                                @if(!empty($lab->tooth))
                                    <span style="font-size: 11px; color: #64748b;">(Tooth #{{ $lab->tooth }}{{ $lab->shade ? ', Shade: ' . $lab->shade : '' }})</span>
                                @endif
                            </td>
                            <td class="text-center">1</td>
                            <td class="text-right">{{ number_format($cost, 0) }}</td>
                            <td class="text-right font-weight-bold">{{ number_format($cost, 0) }}</td>
                        </tr>
                    @endif

                    {{-- Fallback Row --}}
                    @if(!$hasItems)
                        <tr>
                            <td class="text-center">1</td>
                            <td>
                                <strong>Dental Consultation & Clinical Care</strong>
                            </td>
                            <td class="text-center">1</td>
                            <td class="text-right">{{ number_format($invoice->amount, 0) }}</td>
                            <td class="text-right font-weight-bold">{{ number_format($invoice->amount, 0) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>

            <!-- TOTALS & SUMMARY -->
            <div class="summary-wrapper">
                <div class="summary-box">
                    <div class="summary-row bold">
                        <span>Total Bill:</span>
                        <span>UGX {{ number_format($invoice->amount, 0) }}</span>
                    </div>

                    @if(!empty($invoice->insurance_responsibility) && $invoice->insurance_responsibility > 0)
                        <div class="summary-row">
                            <span>Insurance Portion:</span>
                            <span>UGX {{ number_format($invoice->insurance_responsibility, 0) }}</span>
                        </div>
                    @endif

                    @if(!empty($invoice->patient_responsibility) && $invoice->patient_responsibility > 0 && $invoice->patient_responsibility != $invoice->amount)
                        <div class="summary-row">
                            <span>Patient Responsibility:</span>
                            <span>UGX {{ number_format($invoice->patient_responsibility, 0) }}</span>
                        </div>
                    @endif

                    <div class="summary-row bold text-green">
                        <span>Paid Amount:</span>
                        <span>UGX {{ number_format($invoice->paid_amount, 0) }}</span>
                    </div>

                    @php
                        $balance = max(0, (float)$invoice->amount - (float)$invoice->paid_amount);
                    @endphp
                    <div class="summary-row large {{ $balance > 0 ? 'text-red' : 'text-green' }}">
                        <span>Balance Due:</span>
                        <span>UGX {{ number_format($balance, 0) }}</span>
                    </div>
                </div>
            </div>

            <!-- PAYMENT TRANSACTIONS (IF RECORDED) -->
            @if($invoice->transactions && $invoice->transactions->isNotEmpty())
                <div class="section-heading">Payment Transactions Record</div>
                <table class="txn-table">
                    <thead>
                        <tr>
                            <th>Txn ID</th>
                            <th>Date & Time</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th style="text-align: right;">Amount Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoice->transactions as $txn)
                            <tr>
                                <td>#{{ $txn->id }}</td>
                                <td>{{ $txn->created_at?->format('d M, Y H:i') ?? $txn->date ?? '-' }}</td>
                                <td>{{ $txn->method ?? 'Cash' }}</td>
                                <td>{{ $txn->reference ?? '-' }}</td>
                                <td style="text-align: right; font-weight: 700; color: #15803d;">
                                    UGX {{ number_format((float)($txn->amount ?? 0), 0) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <!-- SIGNATURES & STAMP AREA -->
            <div class="signatures-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Authorized Signature</div>
                </div>

                <div class="signature-box" style="width: auto;">
                    <div style="font-family: monospace; font-size: 11px; color: #64748b;">
                        *{{ $invoice->receipt_number ?? 'INV-' . $invoice->id }}*
                    </div>
                    <div style="font-size: 9px; color: #94a3b8; margin-top: 2px;">
                        OFFICIAL DIGITAL RECEIPT TOKEN
                    </div>
                </div>

                <div class="stamp-box">
                    Official Stamp
                </div>
            </div>

            <!-- FOOTER -->
            <div class="invoice-footer">
                <p>{{ $receiptFooter ?? 'Thank you for choosing our clinic. Wishing you a healthy and bright smile!' }}</p>
                <p style="font-size: 9.5px; color: #94a3b8; margin-top: 4px;">
                    This official invoice was computer generated on {{ now()->format('d M, Y \a\t H:i') }} &bull; Page 1 of 1
                </p>
            </div>

        </div>
    </div>

</body>
</html>
