<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>X-Ray & Radiology Requisition - #{{ $xrayRequest->id }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            padding: 24px;
            font-size: 13px;
            line-height: 1.5;
        }
        .container {
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
            padding: 36px 42px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 16px;
            border-bottom: 2px dashed #0f172a;
            margin-bottom: 24px;
        }
        .clinic-name {
            font-size: 20px;
            font-weight: 800;
            color: #0d9488;
            text-transform: uppercase;
            letter-spacing: -0.5px;
        }
        .clinic-sub {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            margin-top: 2px;
        }
        .clinic-meta {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
        }
        .req-badge {
            text-align: right;
            font-family: monospace;
            font-size: 12px;
            font-weight: 800;
            color: #0d9488;
        }
        .req-date {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
        }
        .section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0d9488;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .field-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 14px;
        }
        .field {
            display: flex;
            align-items: baseline;
            gap: 6px;
            flex: 1;
            min-width: 180px;
        }
        .field-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #475569;
            white-space: nowrap;
        }
        .field-line {
            flex: 1;
            border-bottom: 1px dashed #94a3b8;
            padding-bottom: 2px;
            font-weight: 600;
            color: #0f172a;
        }
        .block-section {
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px dashed #cbd5e1;
        }
        .block-box {
            border: 1px dashed #94a3b8;
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 4px;
            min-height: 48px;
            white-space: pre-wrap;
            font-size: 12px;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 20px;
        }
        .sig-block {
            width: 240px;
            text-align: center;
        }
        .sig-line {
            border-bottom: 1px dashed #0f172a;
            margin-bottom: 6px;
            height: 36px;
        }
        .sig-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #475569;
        }
        .print-btn {
            display: block;
            margin: 0 auto 20px;
            padding: 10px 22px;
            background: #0d9488;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        @media print {
            body { background: white; padding: 0; }
            .container { border: none; box-shadow: none; padding: 0; }
            .print-btn { display: none; }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">🖨️ Print X-Ray Requisition</button>

    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <div>
                <div class="clinic-name">{{ $clinicName }}</div>
                <div class="clinic-sub">{{ $clinicDetails }}</div>
                @if($clinicAddress)
                    <div class="clinic-meta">📍 {{ $clinicAddress }}</div>
                @endif
                @if($clinicPhone)
                    <div class="clinic-meta">📞 {{ $clinicPhone }} @if($clinicEmail) | ✉️ {{ $clinicEmail }} @endif</div>
                @endif
            </div>
            <div>
                <div class="req-badge">REQUISITION #{{ str_pad($xrayRequest->id, 5, '0', STR_PAD_LEFT) }}</div>
                <div class="req-date">Date: {{ $xrayRequest->request_date ? \Carbon\Carbon::parse($xrayRequest->request_date)->format('d M, Y') : date('d M, Y') }}</div>
                <div class="req-date">Status: <strong>{{ strtoupper($xrayRequest->status) }}</strong></div>
            </div>
        </div>

        <!-- 1. PATIENT DEMOGRAPHICS -->
        <div class="block-section">
            <div class="section-title">1. Patient Information</div>
            <div class="field-row">
                <div class="field" style="flex: 2;">
                    <span class="field-label">Patient Name:</span>
                    <span class="field-line">{{ $xrayRequest->patient ? $xrayRequest->patient->first_name . ' ' . $xrayRequest->patient->last_name : 'N/A' }}</span>
                </div>
                <div class="field">
                    <span class="field-label">Sex:</span>
                    <span class="field-line">{{ $xrayRequest->patient->gender ?? '—' }}</span>
                </div>
                <div class="field">
                    <span class="field-label">Age:</span>
                    <span class="field-line">
                        @if(!empty($xrayRequest->patient->age))
                            {{ $xrayRequest->patient->age }} yrs
                        @elseif(!empty($xrayRequest->patient->date_of_birth))
                            {{ \Carbon\Carbon::parse($xrayRequest->patient->date_of_birth)->age }} yrs
                        @else
                            —
                        @endif
                    </span>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <span class="field-label">Contact / Tel:</span>
                    <span class="field-line">{{ $xrayRequest->patient->phone ?? $xrayRequest->patient->email ?? '—' }}</span>
                </div>
                <div class="field" style="flex: 2;">
                    <span class="field-label">Address:</span>
                    <span class="field-line">{{ $xrayRequest->patient->address ?? '—' }}</span>
                </div>
            </div>
        </div>

        <!-- 2. RADIOLOGY ORDER DETAILS -->
        <div class="block-section">
            <div class="section-title">2. Imaging & Facility Order Details</div>
            <div class="field-row">
                <div class="field" style="flex: 2;">
                    <span class="field-label">Imaging Facility:</span>
                    <span class="field-line" style="color: #0d9488; font-size: 14px;">{{ $xrayRequest->facility }}</span>
                </div>
                <div class="field">
                    <span class="field-label">Estimated Fee / Amount:</span>
                    <span class="field-line" style="font-weight: 800;">{{ number_format($xrayRequest->amount, 2) }}</span>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <span class="field-label">X-Ray / Scan Type:</span>
                    <span class="field-line">{{ $xrayRequest->xray_type ?: 'General Dental X-Ray' }}</span>
                </div>
                <div class="field">
                    <span class="field-label">Tooth / Anatomical Region:</span>
                    <span class="field-line">{{ $xrayRequest->tooth_region ?: 'Full Dentition / Unspecified' }}</span>
                </div>
            </div>
        </div>

        <!-- 3. CLINICAL INDICATION / REASON -->
        <div class="block-section">
            <div class="section-title">3. Clinical Indication & Special Instructions</div>
            <div class="block-box">{{ $xrayRequest->clinical_notes ?: 'No specific clinical indication provided. Routine diagnostic evaluation.' }}</div>
        </div>

        @if($xrayRequest->report_findings)
        <!-- 4. RADIOLOGICAL FINDINGS (IF COMPLETED) -->
        <div class="block-section">
            <div class="section-title">4. Radiological Findings / Impression</div>
            <div class="block-box">{{ $xrayRequest->report_findings }}</div>
        </div>
        @endif

        <!-- SIGNATURES -->
        <div class="signatures">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Requesting Clinician / Dentist</div>
                <div style="font-size: 11px; font-weight: 600; color: #0d9488; margin-top: 2px;">
                    {{ $xrayRequest->doctor_name ?: 'Authorized Dentist' }}
                </div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Radiology Facility / Radiographer</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                    {{ $xrayRequest->facility }}
                </div>
            </div>
        </div>
    </div>
</body>
</html>
