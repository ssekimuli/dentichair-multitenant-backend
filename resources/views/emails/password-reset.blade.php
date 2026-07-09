<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset OTP</title>
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
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
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
        .otp-container {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 32px 0;
        }
        .otp-code {
            font-size: 32px;
            font-weight: 700;
            color: #2563eb;
            letter-spacing: 6px;
            margin: 0;
            font-family: 'Courier New', Courier, monospace;
        }
        .warning {
            font-size: 13px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: 20px;
            margin-top: 32px;
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
            <h2>Hello {{ $name }},</h2>
            <p>We received a request to reset the password for your account. Please use the following One-Time Password (OTP) to complete the verification process. This code is valid for 15 minutes.</p>
            
            <div class="otp-container">
                <div class="otp-code">{{ $otp }}</div>
            </div>
            
            <p>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>
            
            <div class="warning">
                <strong>Security Reminder:</strong> Never share this OTP code with anyone. Our support team will never ask for this code.
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
