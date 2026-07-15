@component('mail::message')
# Inventory Alert

The following inventory items require your attention:

@if($outOfStockItems->count() > 0)
## 🚨 Out of Stock ({{ $outOfStockItems->count() }})

@component('mail::table')
| Item Name | Supplier | Reorder Level |
| :--- | :--- | :--- |
@foreach($outOfStockItems as $item)
| {{ $item->name }} | {{ $item->supplier ?? 'N/A' }} | {{ $item->reorder_level }} |
@endforeach
@endcomponent
@endif

@if($lowStockItems->count() > 0)
## ⚠️ Low Stock ({{ $lowStockItems->count() }})

@component('mail::table')
| Item Name | Current Stock | Reorder Level | Supplier |
| :--- | :--- | :--- | :--- |
@foreach($lowStockItems as $item)
| {{ $item->name }} | {{ $item->stock }} | {{ $item->reorder_level }} | {{ $item->supplier ?? 'N/A' }} |
@endforeach
@endcomponent
@endif

Please reorder these items at the earliest convenience to avoid service disruption.

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
