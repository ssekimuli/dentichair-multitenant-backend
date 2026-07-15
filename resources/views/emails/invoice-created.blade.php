@component('mail::message')
# New Invoice Issued

Dear {{ $patientName }},

A new invoice has been issued for your recent dental treatment. Below is the summary of charges:

**Invoice ID:** {{ $invoiceId }}  
**Due Date:** {{ $dueDate }}  

@component('mail::table')
| Description | Cost |
| :--- | :--- |
@foreach($treatments as $treatment)
| {{ $treatment['procedure'] }} | ${{ number_format($treatment['cost'], 2) }} |
@endforeach
@if(!empty($otherCharges))
@foreach($otherCharges as $charge)
| {{ $charge['description'] }} | ${{ number_format($charge['amount'], 2) }} |
@endforeach
@endif
| **Total Amount** | **${{ number_format($amount, 2) }}** |
@endcomponent

Please ensure that payment is made on or before the due date. If you have any queries regarding this invoice, don't hesitate to reach out.

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
