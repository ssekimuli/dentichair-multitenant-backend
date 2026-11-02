<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation #{{ $quotation->quotation_number }}</title>
    <style>
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
            color: #0F172A;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 30px 36px;
            background-color: #FFFFFF;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .clinic-header {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .clinic-logo-img {
            max-height: 60px;
            max-width: 120px;
            object-fit: contain;
            display: block;
        }

        .clinic-info h1 {
            margin: 0 0 3px;
            font-size: 20px;
            font-weight: 800;
            color: #0F766E;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .clinic-info .subtitle {
            margin: 0 0 2px;
            color: #64748B;
            font-size: 11px;
            font-style: italic;
        }

        .clinic-info .details {
            margin: 0;
            color: #64748B;
            font-size: 11px;
        }

        .doc-badge-container {
            text-align: right;
        }

        .doc-badge {
            background-color: #0F172A;
            color: #FFFFFF;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 6px 16px;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 8px;
        }

        .doc-meta {
            text-align: right;
            font-size: 11px;
        }

        .doc-meta .quote-num {
            font-weight: 800;
            color: #0F172A;
            margin-bottom: 2px;
        }

        .doc-meta .quote-date {
            color: #64748B;
            margin-bottom: 2px;
        }

        .doc-meta .quote-valid {
            color: #0F766E;
            font-weight: 700;
        }

        .green-divider {
            border-bottom: 2.5px solid #0F766E;
            margin: 10px 0 18px 0;
        }

        .reference-card {
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            background-color: #F8FAFC;
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .ref-col {
            flex: 1;
        }

        .ref-col.left {
            padding-right: 18px;
            border-right: 1px solid #E2E8F0;
        }

        .ref-col.right {
            padding-left: 18px;
        }

        .ref-title {
            font-size: 10px;
            font-weight: 800;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .customer-name {
            font-size: 15px;
            font-weight: 800;
            color: #0F172A;
            margin-bottom: 3px;
        }

        .ref-text {
            font-size: 11px;
            color: #334155;
            margin: 1px 0;
        }

        .status-converted {
            color: #0F766E;
            font-weight: 800;
        }

        .section-heading {
            font-size: 11px;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        table.items-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #CBD5E1;
            margin-bottom: 22px;
        }

        table.items-table th {
            background-color: #F8FAFC;
            color: #0F172A;
            font-size: 11px;
            font-weight: 700;
            padding: 8px 10px;
            border-right: 1px solid #E2E8F0;
            border-bottom: 1px solid #CBD5E1;
        }

        table.items-table td {
            padding: 8px 10px;
            font-size: 11px;
            border-right: 1px solid #E2E8F0;
            border-bottom: 1px solid #E2E8F0;
            color: #0F172A;
        }

        table.items-table tr:nth-child(even) {
            background-color: #FAFCFB;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }

        .bottom-grid {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 35px;
        }

        .notes-column {
            width: 54%;
        }

        .notes-heading {
            font-size: 11px;
            font-weight: 800;
            color: #475569;
            margin-bottom: 3px;
        }

        .notes-content {
            font-size: 11px;
            color: #334155;
            margin-bottom: 14px;
            line-height: 1.4;
        }

        .terms-content {
            font-size: 10.5px;
            color: #64748B;
            line-height: 1.4;
        }

        .totals-column {
            width: 40%;
        }

        .totals-box {
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            background-color: #FAFCFB;
            padding: 12px 16px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 11px;
            color: #64748B;
        }

        .totals-row .val {
            font-weight: 700;
            color: #0F172A;
        }

        .totals-divider {
            border-top: 1px solid #E2E8F0;
            margin: 8px 0 6px 0;
        }

        .total-due-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding-top: 4px;
        }

        .total-due-label {
            font-size: 13px;
            font-weight: 800;
            color: #0F172A;
        }

        .total-due-value {
            font-size: 15px;
            font-weight: 900;
            color: #0F766E;
        }

        .signatures-area {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
        }

        .sig-block {
            width: 44%;
            text-align: left;
        }

        .sig-line {
            border-top: 1.5px solid #94A3B8;
            width: 100%;
            margin-bottom: 6px;
        }

        .sig-label {
            font-size: 11px;
            color: #64748B;
        }

        @media print {
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <!-- Top Header -->
    <div class="header">
        <div class="clinic-header">
            @if(!empty($clinicLogo))
                <img src="{{ $clinicLogo }}" alt="{{ $clinicName }} Logo" class="clinic-logo-img">
            @endif
            <div class="clinic-info">
                <h1>{{ $clinicName }}</h1>
                @if(!empty($subtitle))
                    <div class="subtitle">{{ $subtitle }}</div>
                @endif
                <div class="details">
                    {{ $clinicAddress }}<br>
                    Email: {{ $clinicEmail }}@if(!empty($clinicPhone)) • Phone: {{ $clinicPhone }}@endif
                </div>
            </div>
        </div>

        <div class="doc-badge-container">
            <div class="doc-badge">TREATMENT QUOTATION</div>
            <div class="doc-meta">
                <div class="quote-num">Quote #: {{ $quotation->quotation_number }}</div>
                <div class="quote-date">Date: {{ $quotation->quotation_date ? $quotation->quotation_date->format('Y-m-d') : date('Y-m-d') }}</div>
                <div class="quote-valid">Valid Until: {{ $quotation->valid_until ? $quotation->valid_until->format('Y-m-d') : 'N/A' }}</div>
            </div>
        </div>
    </div>

    <!-- Green Divider Line -->
    <div class="green-divider"></div>

    <!-- Patient Details & Clinical Reference Card -->
    <div class="reference-card">
        <div class="ref-col left">
            <div class="ref-title">{{ $quotation->customer_type === 'patient' ? 'PATIENT DETAILS:' : 'CLIENT DETAILS:' }}</div>
            <div class="customer-name">{{ $quotation->customer_name }}</div>
            @if($quotation->customer_phone)
                <div class="ref-text">Phone: {{ $quotation->customer_phone }}</div>
            @endif
            @if($quotation->customer_email)
                <div class="ref-text">Email: {{ $quotation->customer_email }}</div>
            @endif
        </div>

        <div class="ref-col right">
            <div class="ref-title">CLINICAL REFERENCE:</div>
            <div class="ref-text">Attending: {{ $quotation->dentist ? 'Dr. ' . $quotation->dentist->name : 'General Practice' }}</div>
            <div class="ref-text">Source: {{ strtoupper(str_replace('_', ' ', $quotation->source_type ?? 'MANUAL')) }}</div>
            <div class="ref-text status-converted">Status: {{ strtoupper($quotation->status) }}</div>
        </div>
    </div>

    <!-- Proposed Services & Procedures Table -->
    <div class="section-heading">PROPOSED SERVICES & PROCEDURES</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;" class="text-center">#</th>
                <th style="width: 45%;" class="text-left">Procedure / Item Description</th>
                <th style="width: 10%;" class="text-center">Tooth</th>
                <th style="width: 8%;" class="text-center">Qty</th>
                <th style="width: 16%;" class="text-right">Unit Price ({{ $quotation->currency }})</th>
                <th style="width: 16%;" class="text-right">Total ({{ $quotation->currency }})</th>
            </tr>
        </thead>
        <tbody>
            @forelse($quotation->items as $idx => $item)
            <tr>
                <td class="text-center">{{ $idx + 1 }}</td>
                <td class="text-left">
                    <span style="font-weight: 700;">{{ $item->description }}</span>
                    @if($item->special_instructions)
                        <div style="font-size: 10px; color: #64748B;">{{ $item->special_instructions }}</div>
                    @endif
                </td>
                <td class="text-center">{{ $item->tooth_number ?? '-' }}</td>
                <td class="text-center">{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right" style="font-weight: 700;">{{ number_format($item->total, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-center" style="color: #64748B; padding: 14px;">No items in this quotation.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Bottom Section: Notes & Terms + Totals Card -->
    <div class="bottom-grid">
        <div class="notes-column">
            @if(!empty($quotation->notes))
                <div class="notes-heading">Notes / Clinical Recommendations:</div>
                <div class="notes-content">{{ $quotation->notes }}</div>
            @endif

            <div class="notes-heading">Terms & Conditions:</div>
            <div class="terms-content">
                @if(!empty($quotation->terms_conditions))
                    {!! nl2br(e($quotation->terms_conditions)) !!}
                @else
                    1. Quotation is valid for 14 days from the date of issue.<br>
                    2. Payment terms as agreed upon consultation.
                @endif
            </div>
        </div>

        <div class="totals-column">
            <div class="totals-box">
                <div class="totals-row">
                    <span>Subtotal:</span>
                    <span class="val">{{ $quotation->currency }} {{ number_format($quotation->subtotal, 2) }}</span>
                </div>
                @if($quotation->discount_amount > 0)
                <div class="totals-row" style="color: #16A34A;">
                    <span>Discount:</span>
                    <span class="val" style="color: #16A34A;">- {{ $quotation->currency }} {{ number_format($quotation->discount_amount, 2) }}</span>
                </div>
                @endif
                @if($quotation->tax_amount > 0)
                <div class="totals-row">
                    <span>Tax ({{ number_format($quotation->tax_rate, 1) }}%):</span>
                    <span class="val">+ {{ $quotation->currency }} {{ number_format($quotation->tax_amount, 2) }}</span>
                </div>
                @endif
                <div class="totals-divider"></div>
                <div class="total-due-row">
                    <span class="total-due-label">TOTAL DUE:</span>
                    <span class="total-due-value">{{ $quotation->currency }} {{ number_format($quotation->total, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Signatures -->
    <div class="signatures-area">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Authorized Clinician Signature</div>
        </div>
        <div class="sig-block" style="text-align: right;">
            <div class="sig-line"></div>
            <div class="sig-label">Patient / Client Acceptance Signature</div>
        </div>
    </div>
</body>
</html>
