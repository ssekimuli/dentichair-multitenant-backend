@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php($tenant = app(\App\Services\TenantContext::class)->current())
@php($brandColor = $tenant?->brand_color ?: '#3869d4')
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" style="background-color: {{ $brandColor }}; border-color: {{ $brandColor }};" target="_blank" rel="noopener">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
