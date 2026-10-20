<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Form - {{ $patient->first_name ?? 'Patient' }} {{ $patient->last_name ?? '' }} (Record #{{ $record->id }})</title>
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
            align-items: flex-end;
            padding-bottom: 12px;
            border-bottom: 2px dashed #0f172a;
            margin-bottom: 20px;
        }

        .clinic-brand h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
            text-transform: uppercase;
        }

        .clinic-brand p {
            color: #475569;
            font-size: 11.5px;
            margin-top: 2px;
        }

        .record-badge-box {
            text-align: right;
        }

        .record-ref {
            font-family: monospace;
            font-size: 12px;
            font-weight: 700;
            color: #0f766e;
        }

        .record-date {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }

        /* PATIENT DEMOGRAPHICS (DASH LINES, NO BOXES) */
        .patient-demographics {
            margin-bottom: 22px;
            padding-bottom: 14px;
            border-bottom: 1px dashed #94a3b8;
        }

        .demo-row {
            display: flex;
            gap: 16px;
            margin-bottom: 10px;
            align-items: flex-end;
        }

        .demo-row:last-child {
            margin-bottom: 0;
        }

        .field-group {
            display: flex;
            align-items: flex-end;
            gap: 6px;
        }

        .field-group.flex-1 { flex: 1; }
        .field-group.flex-2 { flex: 2; }
        .field-group.w-28 { width: 110px; }
        .field-group.w-36 { width: 150px; }

        .field-label {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
        }

        .field-dashed-line {
            flex: 1;
            border-bottom: 1px dashed #64748b;
            padding-bottom: 2px;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            min-height: 20px;
        }

        /* SECTION STYLING (CLEAN DASHED DIVIDERS) */
        .section-block {
            margin-bottom: 18px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .section-title {
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f766e;
            white-space: nowrap;
        }

        .dash-trail {
            flex: 1;
            border-bottom: 1px dashed #cbd5e1;
            height: 1px;
        }

        /* DASHED CONTENT LINES (NO BULKY BOXES) */
        .dashed-content-box {
            border-bottom: 1px dashed #94a3b8;
            padding: 4px 0 8px 0;
            font-size: 13px;
            line-height: 22px;
            color: #0f172a;
            white-space: pre-wrap;
            min-height: 28px;
        }

        .dashed-content-box.tall {
            min-height: 60px;
        }

        .dashed-content-box.highlight {
            font-weight: 600;
            color: #0f766e;
        }

        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .col-header {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .col-dash {
            flex: 1;
            border-bottom: 1px dashed #e2e8f0;
        }

        /* CONSENT */
        .consent-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            margin-top: 14px;
            padding: 6px 0;
            border-bottom: 1px dashed #cbd5e1;
        }

        /* SIGNATURES WITH PROFESSIONAL DASHED LINES */
        .signatures-area {
            margin-top: 36px;
            padding-top: 10px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 36px;
            page-break-inside: avoid;
        }

        .sig-col {
            display: flex;
            flex-col;
            gap: 6px;
        }

        .sig-label {
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 36px;
        }

        .sig-line {
            border-bottom: 1px dashed #475569;
            width: 100%;
            margin-bottom: 4px;
        }

        .sig-sub {
            font-size: 11px;
            color: #64748b;
        }

        .footer-note {
            margin-top: 26px;
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
            border-top: 1px dashed #e2e8f0;
            padding-top: 10px;
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

    <!-- ACTIONS (Hidden on print) -->
    <div class="actions-bar">
        <button onclick="window.history.back()" class="btn">
            &larr; Back
        </button>
        <button onclick="window.print()" class="btn btn-primary">
            🖨️ Print Clinical Case Sheet
        </button>
    </div>

    <!-- MAIN FORM -->
    <div class="paper">
        <!-- CLINIC HEADER -->
        <div class="clinic-header">
            <div class="clinic-brand">
                <h1>{{ $clinicName }}</h1>
                @if(!empty($clinicDetails))
                    <p style="font-weight: 500; color: #334155; margin-top: 2px;">{{ $clinicDetails }}</p>
                @endif
                <div style="font-size: 11px; color: #64748b; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                    @if(!empty($clinicAddress))
                        <span>Address: {{ $clinicAddress }}</span>
                    @endif
                    @if(!empty($branchName) && strcasecmp($branchName, $clinicName) !== 0)
                        <span>Branch: {{ $branchName }}</span>
                    @endif
                    @if(!empty($clinicPhone))
                        <span>Tel: {{ $clinicPhone }}</span>
                    @endif
                    @if(!empty($clinicEmail))
                        <span>Email: {{ $clinicEmail }}</span>
                    @endif
                </div>
            </div>
            <div class="record-badge-box">
                <p class="record-ref">CASE RECORD #{{ $record->id }}</p>
                <p class="record-date">
                    Date: {{ $record->date ? \Carbon\Carbon::parse($record->date)->format('M d, Y') : \Carbon\Carbon::parse($record->created_at)->format('M d, Y') }}
                </p>
                @if(!empty($record->doctor_name))
                    <p class="record-date">Clinician: <strong>{{ $record->doctor_name }}</strong></p>
                @endif
            </div>
        </div>

        <!-- PATIENT DEMOGRAPHICS (DASH LINES FORMAT) -->
        <div class="patient-demographics">
            <div class="demo-row">
                <div class="field-group flex-2">
                    <span class="field-label">Patient Name:</span>
                    <span class="field-dashed-line">{{ $patient->first_name ?? '' }} {{ $patient->last_name ?? '' }}</span>
                </div>
                <div class="field-group w-36">
                    <span class="field-label">Sex:</span>
                    <span class="field-dashed-line">{{ $patient->gender ?? 'Unspecified' }}</span>
                </div>
                <div class="field-group w-28">
                    <span class="field-label">Age:</span>
                    <span class="field-dashed-line">
                        @if(!empty($patient->age))
                            {{ $patient->age }} Yrs
                        @elseif(!empty($patient->date_of_birth))
                            {{ \Carbon\Carbon::parse($patient->date_of_birth)->age }} Yrs
                        @else
                            N/A
                        @endif
                    </span>
                </div>
            </div>
            <div class="demo-row">
                <div class="field-group flex-1">
                    <span class="field-label">Contact / Phone:</span>
                    <span class="field-dashed-line">{{ $patient->phone ?? $patient->email ?? 'N/A' }}</span>
                </div>
                <div class="field-group flex-1">
                    <span class="field-label">Attending Clinician:</span>
                    <span class="field-dashed-line">{{ $record->doctor_name ?: 'Authorized Clinician' }}</span>
                </div>
            </div>
        </div>

        <!-- 1. CHIEF COMPLAINT -->
        <div class="section-block">
            <div class="section-header">
                <span class="section-title">1. Chief Complaint</span>
                <span class="dash-trail"></span>
            </div>
            <div class="dashed-content-box">{{ $record->chief_complaint ?: 'None recorded' }}</div>
        </div>

        <!-- HISTORY OF PRESENT COMPLAINT -->
        @if(!empty($record->history_complaint))
        <div class="section-block">
            <div class="section-header">
                <span class="section-title">History of Present Complaint / Illness</span>
                <span class="dash-trail"></span>
            </div>
            <div class="dashed-content-box">{{ $record->history_complaint }}</div>
        </div>
        @endif

        <!-- 2. CLINICAL ORAL EXAMINATION -->
        <div class="section-block">
            <div class="section-header">
                <span class="section-title">2. Clinical Oral Examination</span>
                <span class="dash-trail"></span>
            </div>
            <div class="two-columns">
                <div>
                    <div class="col-header">
                        <span>Extra-Oral Examination</span>
                        <span class="col-dash"></span>
                    </div>
                    <div class="dashed-content-box">{{ $record->extra_oral_exam ?: 'No abnormalities detected (NAD)' }}</div>
                </div>
                <div>
                    <div class="col-header">
                        <span>Intra-Oral Examination</span>
                        <span class="col-dash"></span>
                    </div>
                    <div class="dashed-content-box">{{ $record->intra_oral_exam ?: 'No abnormalities detected (NAD)' }}</div>
                </div>
            </div>
        </div>

        <!-- 3. DIAGNOSIS -->
        <div class="section-block">
            <div class="section-header">
                <span class="section-title">3. Diagnosis</span>
                <span class="dash-trail"></span>
            </div>
            <div class="dashed-content-box highlight">{{ $record->diagnosis ?: 'Clinical assessment under observation' }}</div>
        </div>

        <!-- 4. TREATMENT MANAGEMENT -->
        <div class="section-block">
            <div class="section-header">
                <span class="section-title">4. Treatment Management</span>
                <span class="dash-trail"></span>
            </div>
            <div class="two-columns">
                <div>
                    <div class="col-header">
                        <span>Proposed Treatment Plan</span>
                        <span class="col-dash"></span>
                    </div>
                    <div class="dashed-content-box tall">{{ $record->treatment_plan ?: 'None documented' }}</div>
                </div>
                <div>
                    <div class="col-header">
                        <span>Treatment Given (Today)</span>
                        <span class="col-dash"></span>
                    </div>
                    <div class="dashed-content-box tall">{{ $record->treatment_given ?: 'None rendered during this visit' }}</div>
                </div>
            </div>
        </div>

        <!-- 5. CLINICAL NOTES -->
        @if(!empty($record->treatment_note))
        <div class="section-block">
            <div class="section-header">
                <span class="section-title">5. Clinical Notes & Instructions</span>
                <span class="dash-trail"></span>
            </div>
            <div class="dashed-content-box">{{ $record->treatment_note }}</div>
        </div>
        @endif

        <!-- 6. INFORMED CONSENT -->
        <div class="consent-row">
            <span class="field-label">Informed Consent:</span>
            <span class="field-dashed-line font-semibold">
                {{ $record->consent ? 'Confirmed & Acknowledged' : 'Pending' }}
                @if(!empty($record->consent_notes))
                    &mdash; <em>{{ $record->consent_notes }}</em>
                @endif
            </span>
        </div>

        <!-- SIGNATURES (DASHED LINES) -->
        <div class="signatures-area">
            <div class="sig-col">
                <p class="sig-label">Patient / Guardian Signature:</p>
                <div class="sig-line"></div>
                <p class="sig-sub">Date: ________________________________</p>
            </div>
            <div class="sig-col">
                <p class="sig-label">Attending Dentist Signature & Stamp:</p>
                <div class="sig-line"></div>
                <p class="sig-sub">{{ $record->doctor_name ?: 'Authorized Clinician' }}</p>
            </div>
        </div>

        <div class="footer-note">
            @if(!empty($receiptFooter))
                <div style="margin-bottom: 6px; font-weight: 500; color: #475569;">{{ $receiptFooter }}</div>
            @endif
            Confidential Medical Form &bull; Generated from Dental EMR System &bull; {{ date('Y-m-d H:i') }}
        </div>
    </div>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            if (params.get('print') === '1' || params.has('auto_print')) {
                setTimeout(() => {
                    window.print();
                }, 400);
            }
        });
    </script>
</body>
</html>
