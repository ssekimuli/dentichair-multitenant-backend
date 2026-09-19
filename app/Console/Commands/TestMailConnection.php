<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMailConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test-connection {email : The email address to send a test message to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sends a test email and diagnoses the current mail configuration';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $recipient = $this->argument('email');

        $this->info("=== Diagnosing Mail Configuration ===");
        $this->line("Mailer:        " . config('mail.default'));
        
        $smtpConfig = config('mail.mailers.' . config('mail.default'));
        if ($smtpConfig) {
            $this->line("Host:          " . ($smtpConfig['host'] ?? 'N/A'));
            $this->line("Port:          " . ($smtpConfig['port'] ?? 'N/A'));
            $this->line("Encryption:    " . ($smtpConfig['scheme'] ?? 'N/A'));
            $this->line("Username:      " . ($smtpConfig['username'] ?? 'N/A'));
        }
        
        $this->line("From Address:  " . config('mail.from.address'));
        $this->line("From Name:     " . config('mail.from.name'));
        $this->line("=====================================");

        $this->info("Attempting to send a test email to {$recipient}...");

        try {
            Mail::raw("This is a diagnostic test email from the Dental Management System application. If you receive this, it means your SMTP/mailer settings are functionally correct, though you should still verify if this lands in your inbox or spam folder.", function ($message) use ($recipient) {
                $message->to($recipient)
                        ->subject('Diagnostic Test Email - ' . config('app.name'));
            });

            $this->info("Success! The test email has been sent successfully.");
            $this->info("Please check the inbox (and spam folder) of {$recipient}.");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to send test email!");
            $this->error("Exception message: " . $e->getMessage());
            $this->line("");
            $this->line("Troubleshooting tips:");
            $this->line("1. Double check your credentials (username/password) in the .env file.");
            $this->line("2. Ensure the MAIL_PORT matches the MAIL_SCHEME/encryption method (e.g. 587 for TLS, 465 for SSL).");
            $this->line("3. If using Gmail SMTP, make sure you generated a 16-character App Password (not your primary password) and that 2-Factor Authentication is enabled.");
            $this->line("4. Check if your hosting provider allows outbound connections on your mail port (many cloud providers block port 25/587 by default).");
            return Command::FAILURE;
        }
    }
}
