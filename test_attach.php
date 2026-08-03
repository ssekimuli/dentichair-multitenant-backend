<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Let's create an exact replica of the attach payload
$data = [
    'insurance_policy_id' => '1',
    'subscriber_id' => '243223',
    'group_number' => '',
    'relationship_to_subscriber' => 'child',
    'subscriber_name' => 'projectcode',
    'is_primary' => true
];

$patient = \App\Models\Patient::find(8);
if (!$patient) {
    echo "Patient 8 not found\n";
    exit;
}

try {
    Illuminate\Support\Facades\DB::transaction(function () use ($patient, $data) {
        if (!empty($data['is_primary'])) {
            Illuminate\Support\Facades\DB::table('patient_insurance_policies')
                ->where('patient_id', $patient->id)
                ->update(['is_primary' => false]);
        }

        Illuminate\Support\Facades\DB::table('patient_insurance_policies')->insert([
            'patient_id' => $patient->id,
            'insurance_policy_id' => $data['insurance_policy_id'],
            'subscriber_id' => $data['subscriber_id'],
            'group_number' => $data['group_number'] ?? null,
            'relationship_to_subscriber' => $data['relationship_to_subscriber'],
            'subscriber_name' => $data['subscriber_name'] ?? null,
            'is_primary' => $data['is_primary'] ?? false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    });
    echo "Success!\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
