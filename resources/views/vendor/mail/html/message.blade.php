@php($tenant = app(\App\Services\TenantContext::class)->current())
@php($tenantName = $tenant?->display_name ?: config('app.name'))
@php($applicationUrl = $tenant?->application_url ?: config('app.url'))
@php($brandColor = $tenant?->brand_color ?: '#2563eb')

<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$applicationUrl">
@if ($tenant?->logo_url)
<img src="{{ $tenant->logo_url }}" alt="{{ $tenantName }}" style="max-height: 48px; max-width: 180px;">
@else
<span style="color: {{ $brandColor }};">{{ $tenantName }}</span>
@endif
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $tenantName }}. {{ __('All rights reserved.') }}
@if ($tenant?->support_email)
<br><a href="mailto:{{ $tenant->support_email }}">{{ $tenant->support_email }}</a>
@endif
@if ($tenant?->support_phone)
<br>{{ $tenant->support_phone }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
