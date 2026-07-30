@component('mail::message')
# Business Email Updated

Hello,

The email address associated with your business account has been updated:

* **Old Email:** {{ $oldEmail }}
* **New Email:** {{ $newEmail }}

If you did not initiate this change, please contact our support team immediately.

Thanks,<br>
{{ config('app.name', 'Dental Management System') }}
@endcomponent
