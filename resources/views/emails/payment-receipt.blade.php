@component('mail::message')
# Payment Receipt

Dear {{ $patientName }},

Thank you for your payment. We have successfully processed your transaction. Here is your receipt details:

@component('mail::panel')
**Invoice ID:** {{ $invoiceId }}  
**Payment Date:** {{ $paymentDate }}  
**Payment Method:** {{ $paymentMethod }}  
**Amount Paid:** **${{ number_format($amountPaid, 2) }}**  
**Remaining Balance:** ${{ number_format($remainingBalance, 2) }}  
**Status:** **{{ $invoiceStatus }}**
@endcomponent

If you have any questions or require further assistance, please contact our billing department.

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
