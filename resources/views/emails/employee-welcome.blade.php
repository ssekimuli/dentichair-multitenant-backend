@component('mail::message')
# Welcome to the Team!

Welcome, {{ $name }}!

We are excited to have you join our team. An account has been created for you in the Dental Management System. Here are your temporary login details:

@component('mail::panel')
**Email Address:** {{ $email }}
**Password:** {{ $temporaryPassword }}
**Role Assigned:** {{ $role }}
**Branch:** {{ $branchName }}
@endcomponent

Please log in to change your temporary password and set up your profile.

@component('mail::button', ['url' => $tenantBranding['app_url'] ?? config('app.url')])
Log In to Your Account
@endcomponent

Thanks,<br>
{{ $tenantBranding['display_name'] ?? config('app.name', 'Dental Management System') }}
@endcomponent
