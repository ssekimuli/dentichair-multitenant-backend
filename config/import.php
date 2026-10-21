<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Universal Import Allowed Tables
    |--------------------------------------------------------------------------
    |
    | Only tables listed here are accessible through the Universal Import API.
    | Introspection will discover database schema dynamically, while adhering
    | to the security boundaries, tenant isolation, and defaults defined here.
    |
    */

    'tables' => [
        'patients' => [
            'model' => \App\Models\Patient::class,
            'label' => 'Patients',
            'description' => 'Patient records, demographics, and contact information',
            'permission' => null, // accessible to authorized users
            'tenant_column' => 'branch_id',
            'unique_by' => ['phone', 'email', 'id'],
            'default_unique_by' => 'phone',
            'excluded_columns' => ['avatar'],
            'defaults' => [
                'first_name' => 'Imported',
                'last_name' => 'Patient',
                'status' => 'Active',
                'gender' => 'Other',
                'date_of_birth' => '1999-01-01',
            ],
            'aliases' => [
                'last_visit' => ['date', 'visit date', 'last visit', 'last visit date', 'attendance date', 'seen on', 'date of visit', 'visit_date'],
                'first_name' => ['name', 'patient name', 'full name'],
                'phone' => ['contact', 'contact number', 'phone number', 'telephone', 'mobile', 'tel', 'contacts', 'patient contact', 'phone_number'],
                'history' => ['procedure', 'treatment', 'notes', 'medical history', 'procedure name'],
            ],
            'custom_rules' => [
                'email' => 'nullable|email',
                'gender' => 'nullable|in:Male,Female,Other,male,female,other',
                'status' => 'nullable|in:Active,Inactive,active,inactive',
                'date_of_birth' => 'nullable|date',
                'last_visit' => 'nullable|date',
                'phone' => 'nullable|string',
                'history' => 'nullable|string',
            ],
        ],

        'employees' => [
            'model' => \App\Models\Employee::class,
            'label' => 'Employees & Staff',
            'description' => 'Staff members, clinicians, doctors, and role assignments',
            'permission' => 'manage_employees',
            'tenant_column' => 'branch_id',
            'unique_by' => ['id', 'email', 'phone'],
            'default_unique_by' => 'email',
            'excluded_columns' => ['password', 'avatar', 'last_login_at'],
            'defaults' => [
                'name' => 'Imported Employee',
                'status' => 'Active',
                'role_id' => 4,
                'role' => 'Dentist',
                'salary' => 0.00,
            ],
            'custom_rules' => [
                'email' => 'nullable|email',
                'status' => 'nullable|in:Active,Inactive,On Leave,active,inactive',
                'salary' => 'nullable|numeric|min:0',
                'hire_date' => 'nullable|date',
            ],
        ],

        'treatments' => [
            'model' => \App\Models\Treatment::class,
            'label' => 'Treatments & Procedures',
            'description' => 'Clinical dental procedures and standard pricing',
            'permission' => null,
            'tenant_column' => null,
            'unique_by' => ['id', 'procedure', 'treatment_code'],
            'default_unique_by' => 'procedure',
            'excluded_columns' => [],
            'defaults' => [
                'cost' => 0.00,
            ],
            'custom_rules' => [
                'procedure' => 'required|string|max:255',
                'cost' => 'nullable|numeric|min:0',
            ],
        ],

        'appointments' => [
            'model' => \App\Models\Appointment::class,
            'label' => 'Appointments',
            'description' => 'Scheduled and completed clinic appointments',
            'permission' => null,
            'tenant_column' => 'branch_id',
            'unique_by' => ['id', 'token_number'],
            'default_unique_by' => 'id',
            'excluded_columns' => ['confirmation_token'],
            'defaults' => [
                'status' => 'Scheduled',
                'time' => '09:00',
            ],
            'custom_rules' => [
                'date' => 'required|date',
                'status' => 'nullable|string',
            ],
        ],

        'invoices' => [
            'model' => \App\Models\Invoice::class,
            'label' => 'Invoices',
            'description' => 'Patient billing invoices and charges',
            'permission' => null,
            'tenant_column' => 'branch_id',
            'unique_by' => ['id', 'receipt_number'],
            'default_unique_by' => 'receipt_number',
            'excluded_columns' => [],
            'defaults' => [
                'status' => 'Pending',
                'paid_amount' => 0.00,
            ],
            'custom_rules' => [
                'total_amount' => 'nullable|numeric',
                'status' => 'nullable|in:Pending,Paid,Partially Paid,Cancelled,Overdue',
            ],
        ],

        'medicines' => [
            'model' => \App\Models\Medicine::class,
            'label' => 'Medicines',
            'description' => 'Pharmacy medicines and inventory items',
            'permission' => null,
            'tenant_column' => 'branch_id',
            'unique_by' => ['id', 'name'],
            'default_unique_by' => 'name',
            'excluded_columns' => [],
            'defaults' => [
                'category' => 'General',
            ],
            'custom_rules' => [
                'name' => 'required|string|max:255',
            ],
        ],

        'inventory_items' => [
            'model' => \App\Models\InventoryItem::class,
            'label' => 'Inventory Items',
            'description' => 'Clinic inventory supplies and stock levels',
            'permission' => null,
            'tenant_column' => 'branch_id',
            'unique_by' => ['id', 'name'],
            'default_unique_by' => 'name',
            'excluded_columns' => [],
            'defaults' => [
                'quantity' => 0,
            ],
            'custom_rules' => [
                'name' => 'required|string|max:255',
                'quantity' => 'nullable|integer',
            ],
        ],

        'expenses' => [
            'model' => \App\Models\Expense::class,
            'label' => 'Expenses',
            'description' => 'Branch expenses and operational disbursements',
            'permission' => null,
            'tenant_column' => 'branch_id',
            'unique_by' => ['id'],
            'default_unique_by' => 'id',
            'excluded_columns' => [],
            'defaults' => [
                'amount' => 0.00,
            ],
            'custom_rules' => [
                'amount' => 'required|numeric|min:0',
                'category' => 'nullable|string',
            ],
        ],

        'lab_services' => [
            'model' => \App\Models\LabService::class,
            'label' => 'Lab Services',
            'description' => 'Dental laboratory service catalog',
            'permission' => null,
            'tenant_column' => null,
            'unique_by' => ['id', 'name'],
            'default_unique_by' => 'name',
            'excluded_columns' => [],
            'defaults' => [],
            'custom_rules' => [
                'name' => 'required|string|max:255',
            ],
        ],

        'clients' => [
            'model' => \App\Models\Client::class,
            'label' => 'Clients',
            'description' => 'External business clients and corporate partners',
            'permission' => null,
            'tenant_column' => null,
            'unique_by' => ['id', 'name', 'email'],
            'default_unique_by' => 'name',
            'excluded_columns' => [],
            'defaults' => [],
            'custom_rules' => [
                'name' => 'required|string|max:255',
            ],
        ],

        'insurance_companies' => [
            'model' => \App\Models\InsuranceCompany::class,
            'label' => 'Insurance Companies',
            'description' => 'Insurance providers and health organizations',
            'permission' => null,
            'tenant_column' => null,
            'unique_by' => ['id', 'name'],
            'default_unique_by' => 'name',
            'excluded_columns' => [],
            'defaults' => [
                'status' => 'active',
            ],
            'custom_rules' => [
                'name' => 'required|string|max:255',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Excluded Columns
    |--------------------------------------------------------------------------
    |
    | Columns that must never be presented as writable destination columns
    | in any table across the entire universal import system.
    |
    */

    'system_excluded_columns' => [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
        'password',
        'api_token',
        'tokens',
        'client_uuid',
        'email_verified_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Common Header Aliases
    |--------------------------------------------------------------------------
    |
    | Maps common human spreadsheet column titles to normalized database field
    | names to provide intelligent, zero-friction default column mappings.
    |
    */

    'aliases' => [
        // Name & Identity
        'first_name' => ['first name', 'given name', 'forename', 'fname'],
        'last_name' => ['last name', 'surname', 'family name', 'lname'],
        'name' => ['full name', 'employee name', 'client name', 'patient name', 'item name', 'medicine name', 'provider name', 'title'],
        'gender' => ['sex', 'patient gender'],
        'date_of_birth' => ['dob', 'birth date', 'birthdate', 'date of birth'],
        'age' => ['patient age', 'years old'],
        'nationality' => ['country of origin', 'citizenship'],

        // Contact & Location
        'email' => ['email address', 'e-mail', 'mail', 'contact email'],
        'phone' => ['contact', 'phone number', 'telephone', 'mobile', 'cell phone', 'contact number', 'tel'],
        'address' => ['residential address', 'street address', 'location', 'residence'],

        // Status & Dates
        'status' => ['record status', 'state', 'current status'],
        'hire_date' => ['date of hire', 'hired on', 'start date', 'joining date'],
        'salary' => ['compensation', 'basic salary', 'wage', 'pay rate', 'monthly salary'],
        'role' => ['job title', 'designation', 'position', 'role name'],
        'role_id' => ['role code', 'role id'],
        'last_visit' => ['last visit', 'last visit date', 'visit date', 'attendance date', 'seen on', 'date of visit'],

        // Financial & Clinical
        'cost' => ['procedure cost', 'price', 'fee', 'charge', 'rate'],
        'procedure' => ['procedure name', 'treatment', 'treatment name', 'clinical procedure'],
        'history' => ['procedure', 'treatment', 'medical history', 'notes', 'clinical notes'],
        'category' => ['group', 'classification', 'department', 'type'],
        'quantity' => ['qty', 'stock', 'units', 'stock quantity', 'count'],
        'amount' => ['total amount', 'billed amount', 'expense amount', 'paid amount'],
        'receipt_number' => ['receipt #', 'invoice #', 'invoice number', 'receipt no'],
        'date' => ['appointment date', 'visit date', 'entry date', 'transaction date'],
        'time' => ['appointment time', 'start time', 'time slot'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Limits & Performance
    |--------------------------------------------------------------------------
    */

    'max_file_size_kb' => 102400, // 100 MB
    'allowed_extensions' => ['csv', 'txt', 'xlsx', 'xls'],
    'allowed_mimes' => [
        'text/csv',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/x-csv',
        'application/csv',
    ],
    'chunk_size' => 500,
    'batch_size' => 100,
    'async_threshold' => 300, // rows threshold to trigger queue dispatch
    'queue_name' => 'default',
    'temp_disk' => 'local',
    'temp_directory' => 'imports',
    'cleanup_temp_files' => true,
];
