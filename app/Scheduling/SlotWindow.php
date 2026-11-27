<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

readonly class SlotWindow
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $endsNextDay = false,
    ) {}

    public static function fromLocal(
        string $date,
        string $startTime,
        string $endTime,
        string $timezone,
        string $startErrorKey = 'startTime',
        string $endErrorKey = 'endTime',
    ): self {
        $startsLocal = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$startTime, $timezone);
        $endsLocal = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$endTime, $timezone);

        if ($startsLocal === null) {
            throw ValidationException::withMessages([
                $startErrorKey => __('Enter a valid start time.'),
            ]);
        }

        if ($endsLocal === null) {
            throw ValidationException::withMessages([
                $endErrorKey => __('Enter a valid end time.'),
            ]);
        }

        if ($endTime === $startTime) {
            throw ValidationException::withMessages([
                $endErrorKey => __('End time must be different from start time.'),
            ]);
        }

        $endsNextDay = $endsLocal->lessThan($startsLocal);

        if ($endsNextDay) {
            $endsLocal = $endsLocal->addDay();
        }

        return new self(
            startsAt: $startsLocal->utc(),
            endsAt: $endsLocal->utc(),
            endsNextDay: $endsNextDay,
        );
    }

    public static function fromTimestamps(CarbonImmutable $startsAt, CarbonImmutable $endsAt): self
    {
        return new self(
            startsAt: $startsAt->utc(),
            endsAt: $endsAt->utc(),
        );
    }

    /**
     * @return array{date: string, time: string}
     */
    public function formatLabel(string $timezone): array
    {
        $startsLocal = $this->startsAt->setTimezone($timezone);
        $endsLocal = $this->endsAt->setTimezone($timezone);

        return [
            'date' => $startsLocal->translatedFormat('l j. F Y'),
            'time' => $startsLocal->format('H:i').'–'.$endsLocal->format('H:i'),
        ];
    }
}
