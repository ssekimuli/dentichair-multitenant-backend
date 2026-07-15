@component('mail::message')
# Time for a Checkup

Dear {{ $patientName }},

We noticed it's been about 6 months since your last visit with us on **{{ $lastVisit }}**.

Regular checkups are essential for maintaining a healthy and beautiful smile. A lot can change in 6 months, and coming in for a routine cleaning and exam can help catch any small issues before they become bigger problems.

Please contact our clinic to schedule your next appointment. We look forward to seeing you again soon!

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
