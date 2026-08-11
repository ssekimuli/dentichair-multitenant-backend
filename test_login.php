<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

$email = 'ssekimuliandrew321@gmail.com';
$user = \App\Models\Employee::where('email', $email)->first();

echo "Testing Login\n";
echo "=============\n\n";

if ($user) {
    echo "✓ User found\n";
    echo "Name: " . $user->name . "\n";
    echo "Email: " . $user->email . "\n";
    echo "Role: " . $user->role . "\n";
    echo "Status: " . $user->status . "\n";
    echo "Branch ID: " . $user->branch_id . "\n";

    // Check password
    $password = 'password';
    if (\Illuminate\Support\Facades\Hash::check($password, $user->password)) {
        echo "\n✓ Password is correct!\n";
    } else {
        echo "\n✗ Password is incorrect\n";
    }
} else {
    echo "✗ User not found with email: $email\n";
}
