<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    
    'allowed_methods' => ['*'],

    'allowed_origins' => [
      'https://arisedentalcare.poslycloud.com',
      'https://dental.poslycloud.com',
      'https://zyrendental.poslycloud.com',
      'https://crogendental.poslycloud.com',
      'https://victoriousdental.poslycloud.com',
      'https://shalomdentalcare.poslycloud.com',
      'https://oasisdental.poslycloud.com',
      'https://icondental.poslycloud.com',
      'https://staging.poslycloud.com',
      'https://staging-mu-ten.vercel.app'
      
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];