<?php 
require __DIR__.'/vendor/autoload.php'; 
$app = require_once __DIR__.'/bootstrap/app.php'; 
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class); 
$kernel->bootstrap(); 

$patientId = 8; // Assuming patient 8 based on previous errors
$primaryPolicy = \App\Models\PatientInsurancePolicy::with('policy.package.packageProcedures')
    ->where('patient_id', $patientId)
    ->where('is_primary', true)
    ->where('status', 'active')
    ->first();

dump($primaryPolicy);
