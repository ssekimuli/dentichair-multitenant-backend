@component('mail::message')
# Appointment Reminder

Dear {{ $patientName }},

This is a friendly reminder that you have an appointment scheduled for tomorrow. Please find the details below:

@component('mail::panel')
**Token Number:** **{{ $tokenNumber }}**
**Doctor:** {{ $doctorName }}
**Treatment:** {{ $treatment }}
**Date:** {{ $date }}
**Time:** {{ $time }}
@endcomponent

Please arrive 10 minutes before your scheduled time. If you need to reschedule, please contact us as soon as possible.

Thanks,<br>
{{ $clinicName ?? config('app.name', 'Dental Management System') }}
@endcomponent
