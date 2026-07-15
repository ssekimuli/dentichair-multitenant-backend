@component('mail::message')
# Appointment Update

Dear {{ $patientName }},

Your appointment status has been updated:

@component('mail::panel')
**Status:** **{{ $status }}**
**Token Number:** {{ $tokenNumber }}
**Doctor:** {{ $doctorName }}
**Treatment:** {{ $treatment }}
**Date:** {{ $date }}
**Time:** {{ $time }}
@endcomponent

@if($status === 'Completed')
Thank you for visiting us! We hope you had a comfortable experience. Feel free to contact us if you have any questions post-treatment.
@elseif($status === 'Cancelled')
If you would like to reschedule this appointment, please visit our system or call us to set up a new time.
@endif

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
