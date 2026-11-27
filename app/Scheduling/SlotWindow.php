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

    public static function fromActualTimes(
        CarbonImmutable $plannedStartsAt,
        string $startTime,
        string $endTime,
        string $timezone,
        string $startErrorKey = 'actualStartTime',
        string $endErrorKey = 'actualEndTime',
    ): self {
        if (! preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            throw ValidationException::withMessages([
                $startErrorKey => __('Enter a valid start time.'),
            ]);
        }

        if (! preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            throw ValidationException::withMessages([
                $endErrorKey => __('Enter a valid end time.'),
            ]);
        }

        $plannedStartLocal = $plannedStartsAt->setTimezone($timezone);
        $baseDate = $plannedStartLocal->startOfDay();

        $startsLocal = CarbonImmutable::createFromFormat('!Y-m-d H:i', $baseDate->format('Y-m-d').' '.$startTime, $timezone);

        if ($startsLocal === null) {
            throw ValidationException::withMessages([
                $startErrorKey => __('Enter a valid start time.'),
            ]);
        }

        $smallestDiff = abs($startsLocal->getTimestamp() - $plannedStartLocal->getTimestamp());

        foreach ([-1, 1] as $dayOffset) {
            $candidateDate = $baseDate->addDays($dayOffset)->format('Y-m-d');
            $candidate = CarbonImmutable::createFromFormat('!Y-m-d H:i', $candidateDate.' '.$startTime, $timezone);

            if ($candidate === null) {
                throw ValidationException::withMessages([
                    $startErrorKey => __('Enter a valid start time.'),
                ]);
            }

            $diff = abs($candidate->getTimestamp() - $plannedStartLocal->getTimestamp());

            if ($diff < $smallestDiff) {
                $smallestDiff = $diff;
                $startsLocal = $candidate;
            }
        }

        if ($endTime === $startTime) {
            throw ValidationException::withMessages([
                $endErrorKey => __('End time must be different from start time.'),
            ]);
        }

        $endsLocal = CarbonImmutable::createFromFormat('!Y-m-d H:i', $startsLocal->format('Y-m-d').' '.$endTime, $timezone);

        if ($endsLocal === null) {
            throw ValidationException::withMessages([
                $endErrorKey => __('Enter a valid end time.'),
            ]);
        }

        $endsNextDay = $endsLocal->lessThanOrEqualTo($startsLocal);

        if ($endsNextDay) {
            $endsLocal = $endsLocal->addDay();
        }

        $startsUtc = $startsLocal->utc();
        $endsUtc = $endsLocal->utc();

        if ($endsUtc->lessThanOrEqualTo($startsUtc) || $startsUtc->diffInSeconds($endsUtc) >= 86400) {
            throw ValidationException::withMessages([
                $endErrorKey => __('Actual shift duration must be less than 24 hours.'),
            ]);
        }

        return new self(
            startsAt: $startsUtc,
            endsAt: $endsUtc,
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
