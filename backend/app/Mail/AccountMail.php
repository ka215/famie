<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountMail extends Mailable
{
    public function __construct(public string $title, public string $messageText, public ?string $actionUrl = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Famie: '.$this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account');
    }
}
