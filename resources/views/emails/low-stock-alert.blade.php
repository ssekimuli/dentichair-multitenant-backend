<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Alert</title>
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
        .content h3 {
            font-size: 15px;
            font-weight: 600;
            margin-top: 24px;
            margin-bottom: 12px;
        }
        .content p {
            font-size: 15px;
            margin-top: 0;
            margin-bottom: 24px;
        }
        .alert-badge-danger {
            display: inline-block;
            background-color: #fef2f2;
            color: #dc2626;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 12px;
        }
        .alert-badge-warning {
            display: inline-block;
            background-color: #fef3c7;
            color: #92400e;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 12px;
        }
        .stock-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0 24px 0;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            font-size: 13px;
        }
        .stock-table th {
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stock-table td {
            padding: 10px 12px;
            border-top: 1px solid #e2e8f0;
        }
        .stock-table.danger th {
            background-color: #fef2f2;
            color: #991b1b;
        }
        .stock-table.danger td {
            background-color: #fff;
        }
        .stock-table.warning th {
            background-color: #fef3c7;
            color: #78350f;
        }
        .stock-table.warning td {
            background-color: #fff;
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
            <h2>Inventory Alert</h2>
            <p>The following inventory items require your attention:</p>

            @if($outOfStockItems->count() > 0)
                <span class="alert-badge-danger">🚨 Out of Stock ({{ $outOfStockItems->count() }})</span>
                <table class="stock-table danger">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Supplier</th>
                            <th>Reorder Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($outOfStockItems as $item)
                            <tr>
                                <td>{{ $item->name }}</td>
                                <td>{{ $item->supplier ?? 'N/A' }}</td>
                                <td>{{ $item->reorder_level }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($lowStockItems->count() > 0)
                <span class="alert-badge-warning">⚠️ Low Stock ({{ $lowStockItems->count() }})</span>
                <table class="stock-table warning">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                            <th>Supplier</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lowStockItems as $item)
                            <tr>
                                <td>{{ $item->name }}</td>
                                <td>{{ $item->stock }}</td>
                                <td>{{ $item->reorder_level }}</td>
                                <td>{{ $item->supplier ?? 'N/A' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <p>Please reorder these items at the earliest convenience to avoid service disruption.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Dental Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
