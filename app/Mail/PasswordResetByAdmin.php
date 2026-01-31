<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class PasswordResetByAdmin extends Mailable
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
            subject: 'GenCC Password Reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
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
