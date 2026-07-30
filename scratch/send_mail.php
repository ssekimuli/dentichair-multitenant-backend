<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Mailable;
use App\Mail\EmployeeWelcomeMail;

class TestMailable extends Mailable {
    public $patientName;
    public $lastVisit;

    public function __construct($patientName, $lastVisit) {
        $this->patientName = $patientName;
        $this->lastVisit = $lastVisit;
    }

    public function build() {
        return $this->subject('Routine Checkup Reminder - Test')
                    ->markdown('emails.routine-checkup');
    }
}

$recipient = 'ssekimuliandrew321@gmail.com';

echo "1. Testing Markdown Mailable (Routine Checkup)...\n";
try {
    Mail::to($recipient)->send(new TestMailable('Dr. Lisa Cuddy', '2026-01-15'));
    echo "Success: Markdown Mailable sent successfully!\n\n";
} catch (\Exception $e) {
    echo "Error: Failed to send Markdown Mailable. " . $e->getMessage() . "\n\n";
}

echo "2. Testing Raw Mail (similar to BusinessController)...\n";
try {
    $message = "Hello, this is a test raw email to verify the updated configuration settings using config('mail.from.address') and config('mail.from.name').";
    Mail::raw($message, function($msg) use ($recipient) {
        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name');
        echo "   From details: {$fromName} <{$fromAddress}>\n";
        $msg->from($fromAddress, $fromName);
        $msg->to($recipient)->subject('Test Raw Email');
    });
    echo "Success: Raw email sent successfully!\n\n";
} catch (\Exception $e) {
    echo "Error: Failed to send raw email. " . $e->getMessage() . "\n\n";
}

echo "3. Testing EmployeeWelcomeMail...\n";
try {
    Mail::to($recipient)->send(new EmployeeWelcomeMail([
        'name' => 'John Doe',
        'email' => $recipient,
        'temporaryPassword' => 'TempPassword123',
        'role' => 'Dentist',
        'branchName' => 'Downtown Branch',
        'clinicName' => 'DentiChair Clinic'
    ], 'Welcome to the Team! - Test welcome mail'));
    echo "Success: EmployeeWelcomeMail sent successfully!\n\n";
} catch (\Exception $e) {
    echo "Error: Failed to send EmployeeWelcomeMail. " . $e->getMessage() . "\n\n";
}

echo "All test attempts completed.\n";
