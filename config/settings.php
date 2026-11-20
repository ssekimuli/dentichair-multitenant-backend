<?php

return [
    'currency' => env('CURRENCY_CODE', 'UGX'),
    'currency_symbol' => env('CURRENCY_SYMBOL', 'UGX'), // Using UGX as the default symbol/code fallback
    'sms_cost_per_message' => env('SMS_COST_PER_MESSAGE', 60),

];
