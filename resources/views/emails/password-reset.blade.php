@component('mail::message')
# Password Reset OTP

Hello {{ $name }},

We received a request to reset the password for your account. Please use the following One-Time Password (OTP) to complete the verification process. This code is valid for 15 minutes.

@component('mail::panel')
# **{{ $otp }}**
@endcomponent

If you did not request a password reset, please ignore this email or contact support if you have concerns.

**Security Reminder:** Never share this OTP code with anyone. Our support team will never ask for this code.

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
