<?php

namespace App\Mail;

use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SlotDeletedMail extends Mailable
{
    public function __construct(
        public readonly Team $team,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your shift on :team was cancelled', ['team' => $this->team->name]),
        );
    }

    public function content(): Content
    {
        $timezone = $this->team->timezone;
        $startsLocal = $this->startsAt->setTimezone($timezone);
        $endsLocal = $this->endsAt->setTimezone($timezone);

        return new Content(
            markdown: 'mail.slot-deleted',
            with: [
                'teamName' => $this->team->name,
                'dateLabel' => $startsLocal->translatedFormat('l j. F Y'),
                'timeRange' => $startsLocal->format('H:i').'–'.$endsLocal->format('H:i'),
                'url' => route('my-shifts.index'),
            ],
        );
    }
}
