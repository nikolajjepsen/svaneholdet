<?php

namespace App\Mail;

use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ShiftConfirmedMail extends Mailable
{
    public function __construct(
        public readonly Shift $shift,
        public readonly Slot $slot,
        public readonly Team $team,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your shift on :team is confirmed', ['team' => $this->team->name]),
        );
    }

    public function content(): Content
    {
        $startsLocal = $this->slot->starts_at->setTimezone($this->team->timezone);
        $endsLocal = $this->slot->ends_at->setTimezone($this->team->timezone);

        return new Content(
            markdown: 'mail.shift-confirmed',
            with: [
                'teamName' => $this->team->name,
                'dateLabel' => $startsLocal->translatedFormat('l j. F Y'),
                'timeRange' => $startsLocal->format('H:i').'–'.$endsLocal->format('H:i'),
                'url' => route('my-shifts.index'),
            ],
        );
    }
}
