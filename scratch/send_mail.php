<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Mailable;

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

echo "Sending test email to ssekimuliandrew321@gmail.com...\n";

try {
    Mail::to('ssekimuliandrew321@gmail.com')->send(new TestMailable('Dr. Lisa Cuddy', '2026-01-15'));
    echo "Success: Email sent successfully!\n";
} catch (\Exception $e) {
    echo "Error: Failed to send email. " . $e->getMessage() . "\n";
}
