<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    public function __construct(public readonly string $url, public readonly string $teamName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Invitation to :team', ['team' => $this->teamName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation');
    }
}
