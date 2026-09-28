<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccessRequestVerificationMail extends Mailable
{
    public function __construct(
        public string $fullName,
        public string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your StoX account access request');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.access-request-verification');
    }
}
