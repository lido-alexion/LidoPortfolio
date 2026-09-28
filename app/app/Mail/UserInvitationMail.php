<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UserInvitationMail extends Mailable
{
    public function __construct(public string $plainTextBody) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name', 'StoX');

        return new Envelope(subject: "You're invited to {$appName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.user-invitation', with: [
            'body' => $this->plainTextBody,
        ]);
    }
}
