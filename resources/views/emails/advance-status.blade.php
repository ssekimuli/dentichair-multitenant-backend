@component('mail::message')
# Staff Advance Request Update

Hello {{ $employeeName }},

Your staff advance request has been reviewed. Here are the update details:

@component('mail::panel')
**Amount Requested:** ${{ number_format($amount, 2) }}
**Reason:** {{ $reason }}
**Date:** {{ $date }}
**Status:** **{{ $status }}**
@endcomponent

@if($status === 'Approved')
Your request has been approved. The amount will be disbursed accordingly. Please contact the finance department if you have any questions.
@elseif($status === 'Rejected')
If you have any questions regarding this decision, please reach out to your HR administrator.
@endif

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
