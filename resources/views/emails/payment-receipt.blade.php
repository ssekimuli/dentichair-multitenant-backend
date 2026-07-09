<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt</title>
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
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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
        .receipt-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            margin: 24px 0;
        }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
        }
        .receipt-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }
        .receipt-row strong {
            color: #0f172a;
        }
        .price-badge {
            color: #10b981;
            font-weight: 700;
            font-size: 16px;
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
            <p>Thank you for your payment. We have successfully processed your transaction. Here is your receipt details:</p>
            
            <div class="receipt-card">
                <div class="receipt-row">
                    <span>Invoice ID</span>
                    <strong>{{ $invoiceId }}</strong>
                </div>
                <div class="receipt-row">
                    <span>Payment Date</span>
                    <strong>{{ $paymentDate }}</strong>
                </div>
                <div class="receipt-row">
                    <span>Payment Method</span>
                    <strong>{{ $paymentMethod }}</strong>
                </div>
                <div class="receipt-row">
                    <span>Amount Paid</span>
                    <span class="price-badge">${{ number_format($amountPaid, 2) }}</span>
                </div>
                <div class="receipt-row">
                    <span>Remaining Balance</span>
                    <strong>${{ number_format($remainingBalance, 2) }}</strong>
                </div>
                <div class="receipt-row">
                    <span>Status</span>
                    <strong>{{ $invoiceStatus }}</strong>
                </div>
            </div>
            
            <p>If you have any questions or require further assistance, please contact our billing department.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
