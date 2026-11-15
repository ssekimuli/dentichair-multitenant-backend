@component('mail::message')
# Appointment Rescheduled

Dear {{ $recipientName }},

@if($forDoctor)
An appointment with your patient **{{ $patientName }}** has been rescheduled.
@else
Your appointment has been rescheduled. Please note the new date and time below.
@endif

@component('mail::panel')
**Previous:** {{ $oldDate }} at {{ $oldTime }}
**New:** {{ $newDate }} at {{ $newTime }}
**Token Number:** {{ $tokenNumber }}
**Doctor:** {{ $doctorName }}
**Treatment:** {{ $treatment }}
@endcomponent

@if(!$forDoctor)
If this new time does not work for you, please contact {{ $clinicName }} to arrange another slot.
@endif

Thanks,<br>
{{ $clinicName }}
@endcomponent
