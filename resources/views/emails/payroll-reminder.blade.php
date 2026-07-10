<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Reminder</title>
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
        .month-badge {
            display: inline-block;
            background-color: #e0f2fe;
            color: #0369a1;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .checklist {
            background-color: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 20px 24px;
            margin: 24px 0;
        }
        .checklist-title {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            margin-top: 0;
            margin-bottom: 16px;
        }
        .checklist-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 12px;
            font-size: 14px;
            color: #334155;
        }
        .checklist-item:last-child {
            margin-bottom: 0;
        }
        .check-icon {
            display: inline-block;
            width: 20px;
            height: 20px;
            min-width: 20px;
            border: 2px solid #cbd5e1;
            border-radius: 4px;
            margin-right: 12px;
            margin-top: 1px;
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
            <span class="month-badge">📋 {{ $month }}</span>
            <h2>Dear {{ $recipientName }},</h2>
            <p>This is a reminder that payroll processing for <strong>{{ $month }}</strong> is due. Please ensure all employee hours, deductions, and bonuses are reviewed and finalized.</p>

            <div class="checklist">
                <p class="checklist-title">Payroll Processing Checklist</p>
                <div class="checklist-item">
                    <span class="check-icon"></span>
                    <span>Review attendance records</span>
                </div>
                <div class="checklist-item">
                    <span class="check-icon"></span>
                    <span>Process salary advances and deductions</span>
                </div>
                <div class="checklist-item">
                    <span class="check-icon"></span>
                    <span>Verify employee details and bank information</span>
                </div>
                <div class="checklist-item">
                    <span class="check-icon"></span>
                    <span>Submit payroll for approval</span>
                </div>
            </div>

            <p>Please complete the payroll processing at the earliest to ensure timely salary disbursement.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
