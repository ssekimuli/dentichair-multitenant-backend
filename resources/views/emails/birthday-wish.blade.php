@component('mail::message')
# Happy Birthday! 🎉

Dear {{ $patientName }},

Wishing you a very Happy Birthday! We hope your special day is filled with joy, laughter, and plenty of reasons to smile.

Thank you for trusting us with your dental care. Have a wonderful year ahead!

Warm regards,<br>
**The Team at {{ $clinicName ?? config('app.name', 'Dental Management System') }}**
@endcomponent
