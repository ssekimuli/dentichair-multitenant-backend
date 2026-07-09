<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Invoice Issued</title>
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
        .invoice-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            margin: 24px 0;
        }
        .invoice-card-header {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
            margin-bottom: 16px;
            font-size: 14px;
            color: #64748b;
        }
        .invoice-card-header strong {
            color: #0f172a;
        }
        .invoice-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .invoice-row.total {
            border-top: 1px dashed #e2e8f0;
            padding-top: 12px;
            margin-top: 16px;
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .invoice-row.total .price {
            color: #2563eb;
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
            <p>A new invoice has been issued for your recent dental treatment. Below is the summary of charges:</p>
            
            <div class="invoice-card">
                <div class="invoice-card-header">
                    <span>Invoice ID: <strong>{{ $invoiceId }}</strong></span>
                    <span>Due Date: <strong>{{ $dueDate }}</strong></span>
                </div>
                
                @foreach($treatments as $treatment)
                    <div class="invoice-row">
                        <span>{{ $treatment['procedure'] }}</span>
                        <span>${{ number_format($treatment['cost'], 2) }}</span>
                    </div>
                @endforeach
                
                @if(!empty($otherCharges))
                    @foreach($otherCharges as $charge)
                        <div class="invoice-row">
                            <span>{{ $charge['description'] }}</span>
                            <span>${{ number_format($charge['amount'], 2) }}</span>
                        </div>
                    @endforeach
                @endif
                
                <div class="invoice-row total">
                    <span>Total Amount</span>
                    <span class="price">${{ number_format($amount, 2) }}</span>
                </div>
            </div>
            
            <p>Please ensure that payment is made on or before the due date. If you have any queries regarding this invoice, don't hesitate to reach out.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
