<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to the Team!</title>
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
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
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
        .credentials-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin: 24px 0;
        }
        .credential-item {
            font-size: 14px;
            margin-bottom: 10px;
        }
        .credential-item:last-child {
            margin-bottom: 0;
        }
        .credential-item strong {
            color: #475569;
            display: inline-block;
            width: 100px;
        }
        .cta-button {
            display: inline-block;
            background-color: #4f46e5;
            color: #ffffff !important;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            margin: 20px 0;
            text-align: center;
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
            <h2>Welcome, {{ $name }}!</h2>
            <p>We are excited to have you join our team. An account has been created for you in the Dental Management System. Here are your temporary login details:</p>
            
            <div class="credentials-card">
                <div class="credential-item">
                    <strong>Email Address:</strong> {{ $email }}
                </div>
                <div class="credential-item">
                    <strong>Password:</strong> {{ $temporaryPassword }}
                </div>
                <div class="credential-item">
                    <strong>Role Assigned:</strong> {{ $role }}
                </div>
                <div class="credential-item">
                    <strong>Branch:</strong> {{ $branchName }}
                </div>
            </div>
            
            <p>Please log in to change your temporary password and set up your profile.</p>
            
            <div style="text-align: center;">
                <a href="{{ url('/') }}" class="cta-button">Log In to Your Account</a>
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
