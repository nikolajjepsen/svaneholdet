<?php

namespace App\Scheduling;

use App\Enums\ShiftStatus;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

    public function updateCapacity(
        Slot $slot,
        User $actor,
        int $capacity,
        bool $acceptWarnings = false,
    ): SlotActionResult {
        return DB::transaction(function () use ($slot, $actor, $capacity, $acceptWarnings): SlotActionResult {
            $lockedSlot = Slot::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($slot->id);

            Gate::forUser($actor)->authorize('update', $lockedSlot);

            if ($capacity < 1) {
                throw ValidationException::withMessages([
                    'capacity' => __('Capacity must be at least 1.'),
                ]);
            }

            $confirmedCount = Shift::query()
                ->where('slot_id', $lockedSlot->id)
                ->where('status', ShiftStatus::Confirmed)
                ->count();

            if ($capacity < $lockedSlot->capacity && $capacity < $confirmedCount && ! $acceptWarnings) {
                return new SlotActionResult(warnings: [
                    __('This slot already has :confirmed confirmed shifts. Lowering capacity to :capacity will put it over capacity.', [
                        'confirmed' => $confirmedCount,
                        'capacity' => $capacity,
                    ]),
                ]);
            }

            $lockedSlot->update([
                'capacity' => $capacity,
            ]);

            return new SlotActionResult(slot: $lockedSlot);
        });
    }
}
