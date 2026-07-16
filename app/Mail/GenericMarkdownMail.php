<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericMarkdownMail extends Mailable
{
    use Queueable, SerializesModels;

    public $viewData;
    public $subjectLine;
    public $viewName;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(string $viewName, array $viewData, string $subjectLine)
    {
        $this->viewName = $viewName;
        $this->viewData = $viewData;
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
            markdown: $this->viewName,
            with: $this->viewData
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
