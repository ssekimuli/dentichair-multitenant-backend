<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Request Update</title>
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
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
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
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
        }
        .status-Approved {
            background-color: #dcfce7;
            color: #15803d;
        }
        .status-Rejected {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        .status-Pending {
            background-color: #fef9c3;
            color: #a16207;
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
            <h2>Hello {{ $employeeName }},</h2>
            <p>Your leave request has been reviewed. Here are the update details:</p>
            
            <table class="details-table">
                <tr>
                    <td class="label">Leave Type</td>
                    <td class="value">{{ $leaveType }}</td>
                </tr>
                <tr>
                    <td class="label">Duration</td>
                    <td class="value">{{ $startDate }} to {{ $endDate }}</td>
                </tr>
                <tr>
                    <td class="label">Reason</td>
                    <td class="value">{{ $reason }}</td>
                </tr>
                <tr>
                    <td class="label">Status</td>
                    <td class="value">
                        <span class="status-badge status-{{ $status }}">{{ $status }}</span>
                    </td>
                </tr>
            </table>
            
            @if($status === 'Approved')
                <p>Enjoy your time off! Please ensure any handovers are completed before you leave.</p>
            @elseif($status === 'Rejected')
                <p>If you have any questions regarding this decision, please reach out to your HR administrator.</p>
            @endif
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
