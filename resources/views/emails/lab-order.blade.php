@component('mail::message')
@if(isset($customMessage) && !empty($customMessage))
{!! $customMessage !!}
@else
# Lab Order Details

Hello {{ $clientName }},

A new lab order has been assigned to your laboratory.

**Patient Name:** {{ $patientName }}  
**Item:** {{ $item }}  
@if(isset($tooth) && $tooth)
**Tooth #:** {{ $tooth }}  
@endif
**Status:** {{ $status }}  
**Due Date:** {{ $dueDate }}  

Please contact us if you need more details.
@endif

Thanks,<br>
{{ $tenantBranding['display_name'] ?? config('app.name') }}
@endcomponent
