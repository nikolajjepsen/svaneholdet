<?php

namespace App\Scheduling;

use App\Models\Season;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Slots
{
    public function createSingle(
        Season $season,
        User $actor,
        string $date,
        string $startTime,
        string $endTime,
        int $capacity,
    ): Slot {
        Gate::forUser($actor)->authorize('create', [Slot::class, $season]);

        if ($capacity < 1) {
            throw ValidationException::withMessages([
                'capacity' => __('Capacity must be at least 1.'),
            ]);
        }

        $season->loadMissing('team');
        $timezone = $season->team->timezone;

        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$startTime, $timezone);
        $endsAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$endTime, $timezone);

        if ($startsAt === null) {
            throw ValidationException::withMessages([
                'startTime' => __('Enter a valid start time.'),
            ]);
        }

        if ($endsAt === null) {
            throw ValidationException::withMessages([
                'endTime' => __('Enter a valid end time.'),
            ]);
        }

        if ($endTime === $startTime) {
            throw ValidationException::withMessages([
                'endTime' => __('End time must be different from start time.'),
            ]);
        }

        if ($endsAt->lessThan($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        return Slot::query()->create([
            'season_id' => $season->id,
            'starts_at' => $startsAt->utc(),
            'ends_at' => $endsAt->utc(),
            'capacity' => $capacity,
        ]);
    }
}
