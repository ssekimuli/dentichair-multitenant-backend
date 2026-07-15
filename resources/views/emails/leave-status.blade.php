@component('mail::message')
# Leave Request Update

Hello {{ $employeeName }},

Your leave request has been reviewed. Here are the update details:

@component('mail::panel')
**Leave Type:** {{ $leaveType }}  
**Duration:** {{ $startDate }} to {{ $endDate }}  
**Reason:** {{ $reason }}  
**Status:** **{{ $status }}**
@endcomponent

@if($status === 'Approved')
Enjoy your time off! Please ensure any handovers are completed before you leave.
@elseif($status === 'Rejected')
If you have any questions regarding this decision, please reach out to your HR administrator.
@endif

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
