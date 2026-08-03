@component('mail::message')# Hello {{ $name }},

Please find your requested documents attached.

@if(!empty($customMessage))
{{ $customMessage }}
@endif

@if(count($links) > 0)
Alternatively, you can access them via the following links:
@foreach($links as $link)
- [{{ $link['name'] }}]({{ $link['url'] }})
@endforeach
@endif

Thanks,
{{ $clinicName }}@endcomponent
