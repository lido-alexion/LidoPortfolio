<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NotificationDeliveryMail extends Mailable
{
    public function __construct(
        public readonly string $notificationTitle,
        public readonly string $notificationMessage,
        public readonly ?array $primaryAction = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[StoX] '.$this->notificationTitle);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.notification-delivery');
    }
}
