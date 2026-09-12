<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The storefront contact form, delivered to the business's own support
 * inbox over SMTP (Section 23 — no third-party transactional API, and no
 * contact-message table: Section 24 defines none, and Customer Service
 * works from the inbox, not from a second queue in the admin).
 */
class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $body,
        public readonly ?string $orderNumber = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->orderNumber !== null
                ? "Contact form — order #{$this->orderNumber}"
                : 'Contact form message',
            // From stays the app's own configured sender so the message
            // passes SPF/DKIM; the customer's address goes on Reply-To,
            // which is what support actually needs to answer them.
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.contact-message');
    }
}
