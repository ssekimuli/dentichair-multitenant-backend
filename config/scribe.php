<?php

return [
    'type' => 'static',
    'static' => [
        'output_path' => 'public/docs',
    ],
    'theme' => 'default',
    'intro_text' => <<<INTRO
# Chart Management System API Documentation

This API provides endpoints for managing dental clinic operations including patients, appointments, invoices, and dental records.
INTRO,
    'groups' => [
        'order' => [
            'authentication',
            'patients',
            'appointments', 
            'invoices',
            'employees',
            'management',
        ],
    ],
    'routes' => [
        [
            'match' => [
                'domains' => ['*'],
                'prefixes' => ['api/*'],
                'versions' => ['v1'],
            ],
            'include' => [
                'app/Http/Controllers/AuthController.php',
                'app/Http/Controllers/PatientController.php',
                'app/Http/Controllers/AppointmentController.php',
                'app/Http/Controllers/InvoiceController.php',
                'app/Http/Controllers/EmployeeController.php',
                'app/Http/Controllers/ManagementController.php',
            ],
            'apply' => [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'response_calls' => [
                    'methods' => ['GET'],
                    'config' => [
                        'app.env' => 'documentation',
                    ],
                    'queryParams' => [],
                    'bodyParams' => [],
                    'fileParams' => [],
                ],
            ],
        ],
    ],
];