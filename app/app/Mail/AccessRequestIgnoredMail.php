<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccessRequestIgnoredMail extends Mailable
{
    public function __construct(public string $fullName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your StoX account access request');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.access-request-ignored');
    }
}
