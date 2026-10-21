<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountMail extends Mailable
{
    /** @param array<string, string|int> $parameters */
    public function __construct(public string $kind, public string $uiLocale, public ?string $actionUrl = null, public array $parameters = [])
    {
        $this->locale(config('i18n.locales.'.$uiLocale.'.translation', config('i18n.fallback')));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Famie: '.__('mail.'.$this->kind.'.title', [], $this->locale));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account', with: [
            'title' => __('mail.'.$this->kind.'.title', [], $this->locale),
            'messageText' => __('mail.'.$this->kind.'.body', $this->parameters, $this->locale),
        ]);
    }
}
