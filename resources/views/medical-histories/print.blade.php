<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dental Case Sheet - {{ $patient->first_name ?? 'Patient' }} {{ $patient->last_name ?? '' }} (Record #{{ $record->id }})</title>
    <style>
        :root {
            --primary: #0d9488;
            --primary-dark: #0f766e;
            --primary-light: #f0fdfa;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-card: #f8fafc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--text-main);
            background: #f1f5f9;
            line-height: 1.5;
            padding: 24px;
            font-size: 13px;
        }

        .paper {
            max-width: 850px;
            margin: 0 auto;
            background: #ffffff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        /* ACTIONS BAR (Hidden in print) */
        .actions-bar {
            max-width: 850px;
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
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: #ffffff;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-outline {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #334155;
        }

        .btn-outline:hover {
            background: #f8fafc;
        }

        /* CLINIC HEADER */
        .clinic-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 16px;
            margin-bottom: 20px;
        }

        .clinic-brand h1 {
            font-size: 22px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
            text-transform: uppercase;
        }

        .clinic-brand p {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 2px;
        }

        .record-badge-box {
            text-align: right;
        }

        .record-badge {
            display: inline-block;
            background: var(--primary-light);
            color: var(--primary-dark);
            border: 1px solid #99f6e4;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 11px;
            font-family: monospace;
        }

        .record-date {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* PATIENT DEMOGRAPHICS BOX */
        .patient-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 14px 16px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .patient-meta-item span.label {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 2px;
        }

        .patient-meta-item p.value {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
        }

        /* CLINICAL SECTIONS */
        .section {
            margin-bottom: 18px;
        }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 4px;
        }

        .content-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 12.5px;
            white-space: pre-wrap;
            color: #1e293b;
        }

        .content-box.highlight {
            background: #f0fdfa;
            border-color: #ccfbf1;
            font-weight: 600;
            color: #0f766e;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .consent-banner {
            background: #f0fdfa;
            border: 1px solid #99f6e4;
            border-radius: 6px;
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            margin-top: 14px;
        }

        .consent-tag {
            font-weight: 700;
            color: var(--primary-dark);
        }

        /* SIGNATURES */
        .signatures {
            margin-top: 36px;
            padding-top: 20px;
            border-top: 1px dashed var(--border-color);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
        }

        .signature-line {
            border-bottom: 1px solid #94a3b8;
            margin-top: 45px;
            margin-bottom: 6px;
        }

        .signature-label {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .footer-note {
            margin-top: 28px;
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
            padding-top: 8px;
        }

        /* PRINT STYLES */
        @media print {
            body {
                background: #ffffff;
                padding: 0;
                font-size: 11.5px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .actions-bar {
                display: none !important;
            }

            .paper {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }

            .signatures {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <!-- ACTION BUTTONS (Hidden in Print) -->
    <div class="actions-bar">
        <button onclick="window.history.back()" class="btn btn-outline">
            &larr; Back
        </button>
        <button onclick="window.print()" class="btn btn-primary">
            🖨️ Print Clinical Case Sheet
        </button>
    </div>

    <!-- MAIN PRINTABLE PAPER -->
    <div class="paper">
        <!-- CLINIC HEADER -->
        <div class="clinic-header">
            <div class="clinic-brand">
                <h1>{{ $clinicName ?? 'Dental & Implant Clinic' }}</h1>
                <p>{{ $clinicAddress ?? 'Comprehensive Oral Healthcare & Dental Surgery' }}</p>
                @if(!empty($branch->phone))
                    <p>Tel: {{ $branch->phone }} | Email: {{ $branch->email ?? '' }}</p>
                @endif
            </div>
            <div class="record-badge-box">
                <span class="record-badge">CASE RECORD #{{ $record->id }}</span>
                <p class="record-date">
                    Date: {{ $record->date ? \Carbon\Carbon::parse($record->date)->format('M d, Y') : \Carbon\Carbon::parse($record->created_at)->format('M d, Y') }}
                </p>
                @if(!empty($record->doctor_name))
                    <p class="record-date">Clinician: <strong>{{ $record->doctor_name }}</strong></p>
                @endif
            </div>
        </div>

        <!-- PATIENT DEMOGRAPHICS -->
        <div class="patient-box">
            <div class="patient-meta-item">
                <span class="label">Patient Name</span>
                <p class="value">{{ $patient->first_name ?? '' }} {{ $patient->last_name ?? '' }}</p>
            </div>
            <div class="patient-meta-item">
                <span class="label">Sex / Gender</span>
                <p class="value">{{ $patient->gender ?? 'Unspecified' }}</p>
            </div>
            <div class="patient-meta-item">
                <span class="label">Age</span>
                <p class="value">
                    @if(!empty($patient->age))
                        {{ $patient->age }} Years
                    @elseif(!empty($patient->date_of_birth))
                        {{ \Carbon\Carbon::parse($patient->date_of_birth)->age }} Years
                    @else
                        N/A
                    @endif
                </p>
            </div>
            <div class="patient-meta-item">
                <span class="label">Contact</span>
                <p class="value">{{ $patient->phone ?? $patient->email ?? 'N/A' }}</p>
            </div>
        </div>

        <!-- 1. CHIEF COMPLAINT -->
        <div class="section">
            <h2 class="section-title">1. Chief Complaint</h2>
            <div class="content-box">{{ $record->chief_complaint }}</div>
        </div>

        <!-- 2. HISTORY OF PRESENT COMPLAINT -->
        @if(!empty($record->history_complaint))
        <div class="section">
            <h2 class="section-title">History of Present Complaint / Illness</h2>
            <div class="content-box">{{ $record->history_complaint }}</div>
        </div>
        @endif

        <!-- 3. CLINICAL ORAL EXAMINATION -->
        <div class="section">
            <h2 class="section-title">2. Clinical Oral Examination</h2>
            <div class="grid-2">
                <div>
                    <span style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px;">Extra-Oral Findings:</span>
                    <div class="content-box">{{ $record->extra_oral_exam ?: 'No abnormalities detected (NAD)' }}</div>
                </div>
                <div>
                    <span style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px;">Intra-Oral Findings:</span>
                    <div class="content-box">{{ $record->intra_oral_exam ?: 'No abnormalities detected (NAD)' }}</div>
                </div>
            </div>
        </div>

        <!-- 4. DIAGNOSIS -->
        <div class="section">
            <h2 class="section-title">3. Diagnosis</h2>
            <div class="content-box highlight">{{ $record->diagnosis ?: 'Clinical assessment under observation' }}</div>
        </div>

        <!-- 5. TREATMENT PLAN & TREATMENT GIVEN -->
        <div class="section">
            <div class="grid-2">
                <div>
                    <h2 class="section-title">Proposed Treatment Plan</h2>
                    <div class="content-box" style="min-height: 70px;">{{ $record->treatment_plan ?: 'None documented' }}</div>
                </div>
                <div>
                    <h2 class="section-title">Treatment Given (Today)</h2>
                    <div class="content-box" style="min-height: 70px;">{{ $record->treatment_given ?: 'None rendered during this visit' }}</div>
                </div>
            </div>
        </div>

        <!-- 6. CLINICAL & TREATMENT NOTES -->
        @if(!empty($record->treatment_note))
        <div class="section">
            <h2 class="section-title">Clinical Notes & Post-Op Instructions</h2>
            <div class="content-box">{{ $record->treatment_note }}</div>
        </div>
        @endif

        <!-- 7. INFORMED CONSENT -->
        <div class="consent-banner">
            <div>
                <span class="consent-tag">
                    Informed Consent: {{ $record->consent ? 'Confirmed & Obtained' : 'Pending' }}
                </span>
                @if(!empty($record->consent_notes))
                    <span style="display: block; font-size: 11px; color: #475569; margin-top: 2px;">
                        {{ $record->consent_notes }}
                    </span>
                @endif
            </div>
            <span style="font-size: 11px; color: #0d9488;">✓ Medically Documented</span>
        </div>

        <!-- SIGNATURES -->
        <div class="signatures">
            <div>
                <div class="signature-line"></div>
                <p class="signature-label">Patient / Guardian Signature & Date</p>
            </div>
            <div>
                <div class="signature-line"></div>
                <p class="signature-label">
                    Attending Dentist: {{ $record->doctor_name ?: 'Authorized Practitioner' }} (Stamp & Signature)
                </p>
            </div>
        </div>

        <div class="footer-note">
            Confidential Medical Record &bull; Generated from Dental EMR System &bull; {{ date('Y-m-d H:i') }}
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
