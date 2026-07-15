@component('mail::message')
# Appointment Scheduled

Dear {{ $patientName }},

Your appointment has been successfully scheduled. Here are the details of your upcoming visit:

@component('mail::panel')
**Token Number:** **{{ $tokenNumber }}**
**Doctor:** {{ $doctorName }}
**Treatment:** {{ $treatment }}
**Date:** {{ $date }}
**Time:** {{ $time }}
**Location:** {{ $branchName }}
@endcomponent

If you need to reschedule or cancel your appointment, please contact us at least 24 hours in advance.

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
