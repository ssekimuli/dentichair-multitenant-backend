<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    
    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://arisedentalcare.poslycloud.com',
      'https://dental.poslycloud.com',
      'https://zyrendentalapi.poslycloud.com',
      'https://crogendentalapi.poslycloud.com',
      'https://victoriousdentalapi.poslycloud.com',
      'https://shalomdentalcareapi.poslycloud.com',
      'https://oasisdentalapi.poslycloud.com',
      'https://icondentalapi.poslycloud.com',
      'https://stagingapi.poslycloud.com'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];