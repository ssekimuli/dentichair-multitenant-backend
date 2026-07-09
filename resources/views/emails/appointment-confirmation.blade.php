<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Scheduled</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f7f9;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 580px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid #eef2f5;
        }
        .header {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            padding: 32px 24px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            font-size: 22px;
            font-weight: 600;
            margin: 0;
            letter-spacing: -0.5px;
        }
        .content {
            padding: 40px 32px;
            color: #334155;
            line-height: 1.6;
        }
        .content h2 {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
            margin-top: 0;
            margin-bottom: 16px;
        }
        .content p {
            font-size: 15px;
            margin-top: 0;
            margin-bottom: 24px;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 24px 0;
            background-color: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .details-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        .details-table tr:last-child td {
            border-bottom: none;
        }
        .label {
            font-weight: 600;
            color: #64748b;
            width: 30%;
        }
        .value {
            color: #0f172a;
            font-weight: 500;
        }
        .token-badge {
            display: inline-block;
            background-color: #e0f2fe;
            color: #0369a1;
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 600;
            font-family: monospace;
        }
        .footer {
            background-color: #f8fafc;
            padding: 24px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Dental Management System</h1>
        </div>
        <div class="content">
            <h2>Dear {{ $patientName }},</h2>
            <p>Your appointment has been successfully scheduled. Here are the details of your upcoming visit:</p>
            
            <table class="details-table">
                <tr>
                    <td class="label">Token Number</td>
                    <td class="value"><span class="token-badge">{{ $tokenNumber }}</span></td>
                </tr>
                <tr>
                    <td class="label">Doctor</td>
                    <td class="value">{{ $doctorName }}</td>
                </tr>
                <tr>
                    <td class="label">Treatment</td>
                    <td class="value">{{ $treatment }}</td>
                </tr>
                <tr>
                    <td class="label">Date</td>
                    <td class="value">{{ $date }}</td>
                </tr>
                <tr>
                    <td class="label">Time</td>
                    <td class="value">{{ $time }}</td>
                </tr>
                <tr>
                    <td class="label">Location</td>
                    <td class="value">{{ $branchName }}</td>
                </tr>
            </table>
            
            <p>If you need to reschedule or cancel your appointment, please contact us at least 24 hours in advance.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
