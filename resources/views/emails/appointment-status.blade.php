<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Update</title>
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
            background: linear-gradient(135deg, #475569 0%, #334155 100%);
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
        .status-banner {
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            font-weight: 600;
            font-size: 16px;
            margin: 24px 0;
        }
        .status-Completed {
            background-color: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .status-Cancelled {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .status-Scheduled {
            background-color: #f0f9ff;
            color: #075985;
            border: 1px solid #bae6fd;
        }
        .status-default {
            background-color: #f8fafc;
            color: #475569;
            border: 1px solid #e2e8f0;
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
            <p>Your appointment status has been updated.</p>
            
            <div class="status-banner status-{{ in_array($status, ['Completed', 'Cancelled', 'Scheduled']) ? $status : 'default' }}">
                Status: {{ $status }}
            </div>
            
            <table class="details-table">
                <tr>
                    <td class="label">Token Number</td>
                    <td class="value">{{ $tokenNumber }}</td>
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
            </table>
            
            @if($status === 'Completed')
                <p>Thank you for visiting us! We hope you had a comfortable experience. Feel free to contact us if you have any questions post-treatment.</p>
            @elseif($status === 'Cancelled')
                <p>If you would like to reschedule this appointment, please visit our system or call us to set up a new time.</p>
            @endif
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
