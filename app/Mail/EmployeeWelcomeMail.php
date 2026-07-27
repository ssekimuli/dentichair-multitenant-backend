<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $name;
    public $email;
    public $temporaryPassword;
    public $role;
    public $branchName;
    public $clinicName;

    public $subjectLine;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($data, $subjectLine = 'Welcome to the Team! - Dental Management System')
    {
        $this->name = $data['name'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->temporaryPassword = $data['temporaryPassword'] ?? '';
        $this->role = $data['role'] ?? '';
        $this->branchName = $data['branchName'] ?? '';
        $this->clinicName = $data['clinicName'] ?? '';
        $this->subjectLine = $subjectLine;
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope()
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    /**
     * Get the message content definition.
     *
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content()
    {
        return new Content(
            markdown: 'emails.employee-welcome',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array
     */
    public function attachments()
    {
        return [];
    }
}
