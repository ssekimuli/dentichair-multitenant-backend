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
        /* WATERMARK OF FACILITY NAME */
        .print-watermark {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 9999;
            user-select: none;
            -webkit-user-select: none;
        }

        .print-watermark-text {
            font-size: clamp(30px, 4.5vw, 54px);
            font-weight: 800;
            color: #0f172a;
            opacity: 0.05;
            text-transform: uppercase;
            letter-spacing: 6px;
            transform: rotate(-32deg);
            text-align: center;
            line-height: 1.25;
            max-width: 85%;
            word-break: break-word;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        @media print {
            body { 
                background: white !important; 
                padding: 0 !important; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            }
            .container { border: none !important; box-shadow: none !important; padding: 0 !important; }
            .print-btn { display: none !important; }

            .print-watermark {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                height: 100% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                pointer-events: none !important;
                z-index: 9999 !important;
            }

            .print-watermark-text {
                font-size: clamp(34px, 5.5vw, 58px) !important;
                font-weight: 900 !important;
                color: #0f172a !important;
                opacity: 0.075 !important;
                text-transform: uppercase !important;
                letter-spacing: 6px !important;
                transform: rotate(-32deg) !important;
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
    <!-- FACILITY NAME WATERMARK -->
    @if(!empty($clinicName))
        <div class="print-watermark" aria-hidden="true">
            <div class="print-watermark-text">{{ $clinicName }}</div>
        </div>
    @endif

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
