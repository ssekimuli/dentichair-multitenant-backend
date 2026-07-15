@component('mail::message')
# Welcome to Our Practice!

Welcome to our clinic, {{ $name }}!

Thank you for choosing us as your partner in dental health. We are committed to providing you with the highest quality care in a comfortable and friendly environment.

@component('mail::panel')
### Your Patient Profile
We have successfully registered your record in our system under the email **{{ $email }}** at our **{{ $branchName }}** branch.
@endcomponent

Our team looks forward to meeting you during your first visit! If you have any questions or would like to schedule an appointment, feel free to reply to this email or call our front desk.

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
