<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NotificationChannelTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify StoX email notifications');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.notification-channel-test');
    }
}
