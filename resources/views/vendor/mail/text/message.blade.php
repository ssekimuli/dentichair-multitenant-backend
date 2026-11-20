@php($tenant = app(\App\Services\TenantContext::class)->current())
@php($tenantName = $tenant?->display_name ?: config('app.name'))
@php($applicationUrl = $tenant?->application_url ?: config('app.url'))

<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$applicationUrl">
{{ $tenantName }}
</x-mail::header>
</x-slot:header>

{{ $slot }}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $tenantName }}. @lang('All rights reserved.')
@if ($tenant?->support_email)
{{ $tenant->support_email }}
@endif
@if ($tenant?->support_phone)
{{ $tenant->support_phone }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
