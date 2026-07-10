<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Routine Checkup Reminder</title>
    <style>
        /* Modern Premium Email Reset */
        body, table, td, div, p, a { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        body { background-color: #f8fafc; margin: 0; padding: 0; color: #334155; }
        .wrapper { width: 100%; background-color: #f8fafc; padding: 40px 0; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01); border: 1px solid #f1f5f9; }
        .header { background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%); padding: 40px 32px; text-align: center; }
        .header h1 { font-size: 24px; font-weight: 700; color: #ffffff; letter-spacing: -0.025em; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .content { padding: 48px 40px; }
        h2 { color: #0f172a; font-size: 22px; font-weight: 700; margin-top: 0; margin-bottom: 24px; letter-spacing: -0.025em; }
        p { font-size: 16px; line-height: 1.6; margin-top: 0; margin-bottom: 24px; color: #475569; }
        .info-card, .details-table, .checklist, .stock-table { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 32px; }
        .details-table { width: 100%; border-collapse: collapse; padding: 0; }
        .details-table td { padding: 12px 0; border-bottom: 1px solid #f1f5f9; font-size: 15px; }
        .details-table tr:last-child td { border-bottom: none; padding-bottom: 0; }
        .details-table tr:first-child td { padding-top: 0; }
        .label { color: #64748b; font-weight: 500; width: 35%; }
        .value { color: #0f172a; font-weight: 600; text-align: right; }
        .btn, .action-button { display: inline-block; background-color: #2563eb; color: #ffffff; font-weight: 600; font-size: 16px; text-decoration: none; padding: 14px 28px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        .badge, .reminder-badge, .month-badge, .token-badge, .status-badge { display: inline-block; padding: 6px 12px; border-radius: 9999px; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 16px; background-color: #dbeafe; color: #1d4ed8; }
        .alert-badge-danger { display: inline-block; padding: 6px 12px; border-radius: 9999px; font-size: 13px; font-weight: 600; margin-bottom: 12px; background-color: #fee2e2; color: #b91c1c; }
        .alert-badge-warning { display: inline-block; padding: 6px 12px; border-radius: 9999px; font-size: 13px; font-weight: 600; margin-bottom: 12px; background-color: #fef3c7; color: #b45309; }
        .stock-table th { padding: 10px 12px; text-align: left; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stock-table td { padding: 10px 12px; border-top: 1px solid #e2e8f0; }
        .footer { background-color: #f1f5f9; padding: 32px 40px; text-align: center; font-size: 13px; color: #64748b; }
        .info-title { font-weight: 700; color: #0369a1; margin-bottom: 8px; font-size: 15px; }
        .info-body { font-size: 14px; color: #0f172a; }
        .checklist-title { font-size: 14px; font-weight: 600; color: #0f172a; margin-top: 0; margin-bottom: 16px; }
        .checklist-item { display: flex; align-items: flex-start; margin-bottom: 12px; font-size: 14px; color: #334155; }
        .check-icon { display: inline-block; width: 20px; height: 20px; min-width: 20px; border: 2px solid #cbd5e1; border-radius: 4px; margin-right: 12px; margin-top: 1px; }
    </style>
</head>
<body>
    <div class="wrapper">
    <div class="container">
        <div class="header">
            <h1>Dental Management System</h1>
        </div>
        <div class="content">
            <span class="reminder-badge">🦷 Time for a Checkup</span>
            <h2>Dear {{ $patientName }},</h2>
            <p>We noticed it's been about 6 months since your last visit with us on <strong>{{ $lastVisit }}</strong>.</p>
            <p>Regular checkups are essential for maintaining a healthy and beautiful smile. A lot can change in 6 months, and coming in for a routine cleaning and exam can help catch any small issues before they become bigger problems.</p>
            
            <p>Please contact our clinic to schedule your next appointment.</p>
            
            <p>We look forward to seeing you again soon!</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
    </div>
</body>
</html>
