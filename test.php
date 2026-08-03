<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $policies = \App\Models\Patient::first()->insurancePolicies()->with('package')->get();
    echo 'SUCCESS: ' . count($policies);
} catch (\Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
