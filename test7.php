<?php 
require __DIR__.'/vendor/autoload.php'; 
$app = require_once __DIR__.'/bootstrap/app.php'; 
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class); 
$kernel->bootstrap(); 
$it = App\Models\InvoiceTreatment::first();
if ($it) {
    dump($it->toArray());
} else {
    echo "No InvoiceTreatment found";
}
