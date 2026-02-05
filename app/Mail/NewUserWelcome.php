<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class NewUserWelcome extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        protected $user,
        protected string $temporaryPassword
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('gencc@thegencc.org', 'GenCC User Notification'),
            subject: 'Welcome to the GenCC Submission Portal',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-user-welcome',
            with: [
                'user' => $this->user,
                'pw' => $this->temporaryPassword,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
