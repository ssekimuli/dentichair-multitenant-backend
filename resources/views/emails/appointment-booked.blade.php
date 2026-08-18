<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f9fafb;
            border-radius: 8px;
        }
        .header {
            background-color: #2563eb;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            background-color: white;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .section {
            margin-bottom: 25px;
        }
        .section h2 {
            color: #2563eb;
            font-size: 16px;
            margin-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 8px;
        }
        .info-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            width: 150px;
            color: #6b7280;
        }
        .info-value {
            flex: 1;
            color: #1f2937;
        }
        .token {
            background-color: #f3f4f6;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            margin: 15px 0;
            border-left: 4px solid #2563eb;
        }
        .token-label {
            color: #6b7280;
            font-size: 12px;
            text-transform: uppercase;
        }
        .token-value {
            font-size: 24px;
            font-weight: bold;
            color: #2563eb;
            font-family: monospace;
            margin-top: 5px;
        }
        .footer {
            background-color: #f9fafb;
            padding: 20px;
            text-align: center;
            color: #6b7280;
            font-size: 12px;
            border-radius: 5px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Appointment Booked</h1>
        </div>

        <div class="content">
            <p>Hello {{ $appointment->doctor_name }},</p>

            <p>A new appointment has been booked for you. Please find the details below:</p>

            <div class="section">
                <h2>Patient Information</h2>
                <div class="info-row">
                    <div class="info-label">Name:</div>
                    <div class="info-value">{{ $appointment->patient->first_name }} {{ $appointment->patient->last_name }}</div>
                </div>
                @if ($appointment->patient->email)
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value">{{ $appointment->patient->email }}</div>
                    </div>
                @endif
                @if ($appointment->patient->phone)
                    <div class="info-row">
                        <div class="info-label">Phone:</div>
                        <div class="info-value">{{ $appointment->patient->phone }}</div>
                    </div>
                @endif
                @if ($appointment->patient->gender)
                    <div class="info-row">
                        <div class="info-label">Gender:</div>
                        <div class="info-value">{{ $appointment->patient->gender }}</div>
                    </div>
                @endif
            </div>

            <div class="section">
                <h2>Appointment Details</h2>
                <div class="info-row">
                    <div class="info-label">Treatment:</div>
                    <div class="info-value">{{ $appointment->treatment }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date:</div>
                    <div class="info-value">{{ \Carbon\Carbon::parse($appointment->date)->format('F d, Y') }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Time:</div>
                    <div class="info-value">{{ \Carbon\Carbon::parse($appointment->time, 'UTC')->format('h:i A') }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">{{ $appointment->status }}</div>
                </div>
            </div>

            <div class="token">
                <div class="token-label">Appointment Token</div>
                <div class="token-value">{{ $appointment->token_number }}</div>
            </div>

            <div class="section">
                <h2>Branch Information</h2>
                <div class="info-row">
                    <div class="info-label">Branch:</div>
                    <div class="info-value">{{ $appointment->branch->name }}</div>
                </div>
            </div>

            <p>Please ensure you are available at the scheduled time. If there are any issues or conflicts, please contact the clinic immediately.</p>
        </div>

        <div class="footer">
            <p>This is an automated email notification. Please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
