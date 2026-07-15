@component('mail::message')
# Payroll Reminder

Dear {{ $recipientName }},

This is a reminder that payroll processing for **{{ $month }}** is due. Please ensure all employee hours, deductions, and bonuses are reviewed and finalized.

### Payroll Processing Checklist
* [ ] Review attendance records
* [ ] Process salary advances and deductions
* [ ] Verify employee details and bank information
* [ ] Submit payroll for approval

Please complete the payroll processing at the earliest to ensure timely salary disbursement.

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
