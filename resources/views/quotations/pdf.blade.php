<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation #{{ $quotation->quotation_number }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            font-size: 13px;
            line-height: 1.5;
            margin: 0;
            padding: 30px;
            background-color: #fff;
        }
        .header {
            border-bottom: 2px solid #2563eb;
            padding-bottom: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .clinic-info h1 {
            margin: 0 0 5px;
            font-size: 22px;
            color: #1e3a8a;
        }
        .clinic-info p {
            margin: 2px 0;
            color: #64748b;
            font-size: 12px;
        }
        .doc-details {
            text-align: right;
        }
        .doc-title {
            font-size: 20px;
            font-weight: bold;
            color: #1e40af;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .meta-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            gap: 20px;
        }
        .meta-box {
            flex: 1;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 15px;
        }
        .meta-box h3 {
            margin: 0 0 8px;
            font-size: 13px;
            text-transform: uppercase;
            color: #475569;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 4px;
        }
        .meta-box p {
            margin: 4px 0;
            font-size: 12px;
        }
        table.items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        table.items-table th {
            background-color: #1e40af;
            color: #ffffff;
            font-weight: 600;
            text-align: left;
            padding: 10px 12px;
            font-size: 12px;
            text-transform: uppercase;
        }
        table.items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
        }
        table.items-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .summary-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 25px;
        }
        .summary-table {
            width: 320px;
            border-collapse: collapse;
        }
        .summary-table td {
            padding: 6px 12px;
            font-size: 13px;
        }
        .summary-table tr.total-row td {
            font-weight: bold;
            font-size: 15px;
            border-top: 2px solid #1e3a8a;
            color: #1e3a8a;
            padding-top: 10px;
        }
        .notes-terms {
            margin-top: 20px;
            border-top: 1px dashed #cbd5e1;
            padding-top: 15px;
        }
        .notes-terms h4 {
            margin: 0 0 5px;
            color: #334155;
            font-size: 12px;
            text-transform: uppercase;
        }
        .notes-terms p {
            margin: 0 0 10px;
            color: #64748b;
            font-size: 11px;
            white-space: pre-line;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-draft { background: #e2e8f0; color: #475569; }
        .status-sent { background: #dbeafe; color: #1e40af; }
        .status-approved { background: #dcfce7; color: #15803d; }
        .status-rejected { background: #fee2e2; color: #b91c1c; }
        .status-converted { background: #fef3c7; color: #b45309; }
        .status-cancelled { background: #f1f5f9; color: #94a3b8; }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div style="display: flex; align-items: center; gap: 18px;">
            @if(!empty($clinicLogo))
                <div class="clinic-logo-wrapper">
                    <img src="{{ $clinicLogo }}" alt="{{ $clinicName }} Logo" class="clinic-logo-img" style="max-height: 65px; max-width: 150px; object-fit: contain; display: block;">
                </div>
            @endif
            <div class="clinic-info">
                <h1>{{ $clinicName }}</h1>
                <p>{{ $clinicAddress }}</p>
                <p>Phone: {{ $clinicPhone }} | Email: {{ $clinicEmail }}</p>
            </div>
        </div>
        <div class="doc-details">
            <div class="doc-title">Quotation</div>
            <p><strong>Quote #:</strong> {{ $quotation->quotation_number }}</p>
            <p><strong>Status:</strong> <span class="status-badge status-{{ $quotation->status }}">{{ ucfirst($quotation->status) }}</span></p>
            <p><strong>Date:</strong> {{ $quotation->quotation_date?->format('M d, Y') }}</p>
            <p><strong>Valid Until:</strong> {{ $quotation->valid_until?->format('M d, Y') }}</p>
        </div>
    </div>

    <div class="meta-grid">
        <div class="meta-box">
            <h3>{{ $quotation->customer_type === 'patient' ? 'Patient Information' : 'Laboratory Client Information' }}</h3>
            <p><strong>Name:</strong> {{ $quotation->customer_name }}</p>
            @if($quotation->customer_email)
                <p><strong>Email:</strong> {{ $quotation->customer_email }}</p>
            @endif
            @if($quotation->customer_phone)
                <p><strong>Phone:</strong> {{ $quotation->customer_phone }}</p>
            @endif
            @if($quotation->customer_type === 'patient' && $quotation->patient?->gender)
                <p><strong>Gender/Age:</strong> {{ ucfirst($quotation->patient->gender) }} / {{ $quotation->patient->age ?? 'N/A' }}</p>
            @endif
        </div>

        <div class="meta-box">
            <h3>Clinical / Order Reference</h3>
            @if($quotation->dentist)
                <p><strong>Attending Dentist:</strong> Dr. {{ $quotation->dentist->name }}</p>
            @endif
            @if($quotation->labOrder)
                <p><strong>Lab Order #:</strong> #{{ $quotation->labOrder->id }} ({{ $quotation->labOrder->item }})</p>
            @endif
            @if($quotation->expected_delivery_date)
                <p><strong>Expected Delivery:</strong> {{ $quotation->expected_delivery_date->format('M d, Y') }}</p>
            @endif
            <p><strong>Source:</strong> {{ ucfirst(str_replace('_', ' ', $quotation->source_type)) }}</p>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Description</th>
                <th>Tooth</th>
                <th>Details</th>
                <th class="text-center">Qty</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Disc.</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $idx => $item)
            <tr>
                <td>{{ $idx + 1 }}</td>
                <td>
                    <strong>{{ $item->description }}</strong>
                    @if($item->special_instructions)
                        <br><small style="color: #64748b;">{{ $item->special_instructions }}</small>
                    @endif
                </td>
                <td>{{ $item->tooth_number ?? '-' }}</td>
                <td>
                    @if($item->material || $item->shade)
                        <small>{{ $item->material ? 'Mat: '.$item->material : '' }} {{ $item->shade ? 'Shade: '.$item->shade : '' }}</small>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">{{ $item->discount_amount > 0 ? number_format($item->discount_amount, 2) : '-' }}</td>
                <td class="text-right">{{ number_format($item->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary-container">
        <table class="summary-table">
            <tr>
                <td>Subtotal:</td>
                <td class="text-right">{{ number_format($quotation->subtotal, 2) }} {{ $quotation->currency }}</td>
            </tr>
            @if($quotation->discount_amount > 0)
            <tr>
                <td>Discount:</td>
                <td class="text-right">-{{ number_format($quotation->discount_amount, 2) }} {{ $quotation->currency }}</td>
            </tr>
            @endif
            @if($quotation->tax_amount > 0)
            <tr>
                <td>Tax ({{ number_format($quotation->tax_rate, 1) }}%):</td>
                <td class="text-right">+{{ number_format($quotation->tax_amount, 2) }} {{ $quotation->currency }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>Total:</td>
                <td class="text-right">{{ number_format($quotation->total, 2) }} {{ $quotation->currency }}</td>
            </tr>
        </table>
    </div>

    @if($quotation->notes || $quotation->terms_conditions)
    <div class="notes-terms">
        @if($quotation->notes)
            <h4>Notes & Instructions:</h4>
            <p>{{ $quotation->notes }}</p>
        @endif
        @if($quotation->terms_conditions)
            <h4>Terms & Conditions:</h4>
            <p>{{ $quotation->terms_conditions }}</p>
        @endif
    </div>
    @endif

    <div class="footer">
        <p>This quotation is generated by {{ $clinicName }} and is valid until {{ $quotation->valid_until?->format('M d, Y') }}.</p>
    </div>
</body>
</html>

