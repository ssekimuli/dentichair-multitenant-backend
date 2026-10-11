<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dental Clinical Record - {{ $patient->first_name ?? 'Patient' }} {{ $patient->last_name ?? '' }} (Record #{{ $record->id }})</title>
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
            🖨️ Print Clinical Case Sheet
        </button>
    </div>

    <!-- MAIN FORM -->
    <div class="paper">
        <!-- CLINIC HEADER -->
        <div class="clinic-header">
            <div class="clinic-brand">
                <h1>{{ $clinicName ?? 'Dental & Oral Healthcare Clinic' }}</h1>
                <p>{{ $clinicAddress ?? 'Comprehensive Oral Surgery, Restorative & Preventive Care' }}</p>
                @if(!empty($branch->phone))
                    <p>Phone: {{ $branch->phone }} @if(!empty($branch->email))| Email: {{ $branch->email }}@endif</p>
                @endif
            </div>
            <div class="record-badge-box">
                <p class="record-ref">CASE RECORD #{{ $record->id }}</p>
                <p class="record-date">
                    Date: {{ $record->date ? CarbonCarbon::parse($record->date)->format('M d, Y') : CarbonCarbon::parse($record->created_at)->format('M d, Y') }}
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
                            {{ CarbonCarbon::parse($patient->date_of_birth)->age }} Yrs
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
                    <span class="field-label">Attending Doctor:</span>
                    <span class="field-dashed-line">{{ $record->doctor_name ?: 'Authorized Dental Surgeon' }}</span>
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
            Confidential Dental Medical Record &bull; Generated from Dental EMR System &bull; {{ date('Y-m-d H:i') }}
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
