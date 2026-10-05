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
        public readonly array $items = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->items ? '[StoX] Daily notification digest' : '[StoX] '.$this->notificationTitle);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification-delivery-html',
            text: 'emails.notification-delivery',
            with: ['items' => $this->items],
        );
    }
}
