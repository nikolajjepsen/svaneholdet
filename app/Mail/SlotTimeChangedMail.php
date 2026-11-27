<?php

namespace App\Mail;

use App\Models\Slot;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SlotTimeChangedMail extends Mailable
{
    public function __construct(
        public readonly Slot $slot,
        public readonly Team $team,
        public readonly CarbonImmutable $oldStartsAt,
        public readonly CarbonImmutable $oldEndsAt,
        public readonly CarbonImmutable $newStartsAt,
        public readonly CarbonImmutable $newEndsAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your shift times on :team changed', ['team' => $this->team->name]),
        );
    }

    public function content(): Content
    {
        $timezone = $this->team->timezone;
        $oldStartsLocal = $this->oldStartsAt->setTimezone($timezone);
        $oldEndsLocal = $this->oldEndsAt->setTimezone($timezone);
        $newStartsLocal = $this->newStartsAt->setTimezone($timezone);
        $newEndsLocal = $this->newEndsAt->setTimezone($timezone);

        return new Content(
            markdown: 'mail.slot-time-changed',
            with: [
                'teamName' => $this->team->name,
                'dateLabel' => $newStartsLocal->translatedFormat('l j. F Y'),
                'oldTimeRange' => $oldStartsLocal->format('H:i').'–'.$oldEndsLocal->format('H:i'),
                'newTimeRange' => $newStartsLocal->format('H:i').'–'.$newEndsLocal->format('H:i'),
                'url' => route('my-shifts.index'),
            ],
        );
    }
}
