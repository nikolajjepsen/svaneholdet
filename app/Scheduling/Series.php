<?php

namespace App\Scheduling;

use App\Enums\ShiftStatus;
use App\Mail\SlotDeletedMail;
use App\Mail\SlotTimeChangedMail;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class Series
{
    public function __construct(
        private readonly ShiftOverlaps $overlaps,
    ) {}

    public function update(
        SlotSeries $series,
        User $actor,
        string $startTime,
        string $endTime,
        int $capacity,
        bool $acceptWarnings = false,
    ): SlotActionResult {
        /** @var array{result: SlotActionResult, notifications: list<array{recipient: User, slot: Slot, team: Team, oldStartsAt: CarbonImmutable, oldEndsAt: CarbonImmutable, newStartsAt: CarbonImmutable, newEndsAt: CarbonImmutable}>} $outcome */
        $outcome = DB::transaction(function () use ($series, $actor, $startTime, $endTime, $capacity, $acceptWarnings): array {
            $lockedSeries = SlotSeries::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($series->id);

            Gate::forUser($actor)->authorize('update', $lockedSeries);

            if ($capacity < 1) {
                throw ValidationException::withMessages([
                    'capacity' => __('Capacity must be at least 1.'),
                ]);
            }

            $team = $lockedSeries->season->team;
            $timezone = $team->timezone;

            // Validate the submitted interval format and ensure start != end.
            SlotWindow::fromLocal('2027-01-01', $startTime, $endTime, $timezone);

            $startChanged = $startTime !== $lockedSeries->local_start_time;
            $endChanged = $endTime !== $lockedSeries->local_end_time;
            $capacityChanged = $capacity !== $lockedSeries->capacity;

            if (! $startChanged && ! $endChanged && ! $capacityChanged) {
                return [
                    'result' => new SlotActionResult(updatedCount: 0),
                    'notifications' => [],
                ];
            }

            $upcomingSlots = Slot::query()
                ->where('slot_series_id', $lockedSeries->id)
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $upcomingSlots->load('confirmedShifts.membership.user');

            $proposedTimeChanges = [];
            $overCapacityWarnings = [];

            foreach ($upcomingSlots as $slot) {
                $slotStartsLocal = $slot->starts_at->setTimezone($timezone);
                $slotEndsLocal = $slot->ends_at->setTimezone($timezone);

                if ($startChanged || $endChanged) {
                    $date = $slotStartsLocal->format('Y-m-d');
                    $effectiveStart = $startChanged ? $startTime : $slotStartsLocal->format('H:i');
                    $effectiveEnd = $endChanged ? $endTime : $slotEndsLocal->format('H:i');

                    $window = SlotWindow::fromLocal($date, $effectiveStart, $effectiveEnd, $timezone);

                    if (! $window->startsAt->equalTo($slot->starts_at) || ! $window->endsAt->equalTo($slot->ends_at)) {
                        $proposedTimeChanges[$slot->id] = [
                            'slot' => $slot,
                            'startsAt' => $window->startsAt,
                            'endsAt' => $window->endsAt,
                            'confirmedShifts' => array_values($slot->confirmedShifts->all()),
                        ];
                    }
                }

                if ($capacityChanged) {
                    $confirmedCount = $slot->capacityCountingShifts($slot->confirmedShifts)->count();

                    if ($capacity < $slot->capacity && $capacity < $confirmedCount) {
                        $slotLabel = SlotWindow::fromTimestamps($slot->starts_at, $slot->ends_at)
                            ->formatLabel($timezone);
                        $overCapacityWarnings[] = __(':date (:time) already has :confirmed confirmed shifts. Lowering capacity to :capacity will put it over capacity.', [
                            'date' => $slotLabel['date'],
                            'time' => $slotLabel['time'],
                            'confirmed' => $confirmedCount,
                            'capacity' => $capacity,
                        ]);
                    }
                }
            }

            $overlapWarnings = $this->overlaps->checkForSlotChanges(
                team: $team,
                proposedSlots: $proposedTimeChanges,
                errorField: 'times',
            );

            $warnings = [...$overCapacityWarnings, ...$overlapWarnings];

            if ($warnings !== [] && ! $acceptWarnings) {
                return [
                    'result' => new SlotActionResult(warnings: $warnings),
                    'notifications' => [],
                ];
            }

            $seriesUpdates = [];

            if ($startChanged) {
                $seriesUpdates['local_start_time'] = $startTime;
            }

            if ($endChanged) {
                $seriesUpdates['local_end_time'] = $endTime;
            }

            if ($capacityChanged) {
                $seriesUpdates['capacity'] = $capacity;
            }

            if ($seriesUpdates !== []) {
                $lockedSeries->update($seriesUpdates);
            }

            $notifications = [];

            foreach ($upcomingSlots as $slot) {
                $slotUpdates = [];
                $oldStartsAt = $slot->starts_at;
                $oldEndsAt = $slot->ends_at;

                if (isset($proposedTimeChanges[$slot->id])) {
                    $slotUpdates['starts_at'] = $proposedTimeChanges[$slot->id]['startsAt'];
                    $slotUpdates['ends_at'] = $proposedTimeChanges[$slot->id]['endsAt'];
                }

                if ($capacityChanged) {
                    $slotUpdates['capacity'] = $capacity;
                }

                if ($slotUpdates === []) {
                    continue;
                }

                $slot->update($slotUpdates);

                if (isset($proposedTimeChanges[$slot->id])) {
                    $newStartsAt = $proposedTimeChanges[$slot->id]['startsAt'];
                    $newEndsAt = $proposedTimeChanges[$slot->id]['endsAt'];

                    foreach ($proposedTimeChanges[$slot->id]['confirmedShifts'] as $shift) {
                        $recipient = $shift->membership->user;

                        if ($recipient->id === $actor->id) {
                            continue;
                        }

                        $notifications[] = [
                            'recipient' => $recipient,
                            'slot' => $slot,
                            'team' => $team,
                            'oldStartsAt' => $oldStartsAt,
                            'oldEndsAt' => $oldEndsAt,
                            'newStartsAt' => $newStartsAt,
                            'newEndsAt' => $newEndsAt,
                        ];
                    }
                }
            }

            return [
                'result' => new SlotActionResult(updatedCount: $upcomingSlots->count()),
                'notifications' => $notifications,
            ];
        });

        foreach ($outcome['notifications'] as $notification) {
            Mail::to($notification['recipient']->email)
                ->locale($notification['recipient']->preferredLocale())
                ->send(new SlotTimeChangedMail(
                    slot: $notification['slot'],
                    team: $notification['team'],
                    oldStartsAt: $notification['oldStartsAt'],
                    oldEndsAt: $notification['oldEndsAt'],
                    newStartsAt: $notification['newStartsAt'],
                    newEndsAt: $notification['newEndsAt'],
                ));
        }

        return $outcome['result'];
    }

    public function delete(
        SlotSeries $series,
        User $actor,
        bool $notifyMembers = true,
    ): void {
        /** @var list<array{recipient: User, team: Team, startsAt: CarbonImmutable, endsAt: CarbonImmutable}> $notifications */
        $notifications = DB::transaction(function () use ($series, $actor, $notifyMembers): array {
            $lockedSeries = SlotSeries::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($series->id);

            Gate::forUser($actor)->authorize('delete', $lockedSeries);

            $slots = Slot::query()
                ->where('slot_series_id', $lockedSeries->id)
                ->orderBy('starts_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($slots as $slot) {
                if ($slot->hasStarted()) {
                    throw ValidationException::withMessages([
                        'series' => __('A slot series cannot be deleted once any of its slots has started.'),
                    ]);
                }
            }

            $notifications = [];

            if ($notifyMembers && $slots->isNotEmpty()) {
                $slotMap = $slots->keyBy('id');
                $team = $lockedSeries->season->team;

                $activeShifts = Shift::query()
                    ->whereIn('slot_id', $slotMap->keys())
                    ->whereIn('status', [ShiftStatus::Confirmed->value, ShiftStatus::Pending->value])
                    ->with('membership.user')
                    ->get();

                foreach ($activeShifts as $shift) {
                    $recipient = $shift->membership->user;

                    if ($recipient->id === $actor->id) {
                        continue;
                    }

                    $slot = $slotMap->get($shift->slot_id);

                    if ($slot === null) {
                        continue;
                    }

                    $notifications[] = [
                        'recipient' => $recipient,
                        'team' => $team,
                        'startsAt' => $slot->starts_at,
                        'endsAt' => $slot->ends_at,
                    ];
                }
            }

            if ($slots->isNotEmpty()) {
                Slot::query()->whereIn('id', $slots->pluck('id'))->delete();
            }

            $lockedSeries->delete();

            return $notifications;
        });

        foreach ($notifications as $notification) {
            Mail::to($notification['recipient']->email)
                ->locale($notification['recipient']->preferredLocale())
                ->send(new SlotDeletedMail(
                    team: $notification['team'],
                    startsAt: $notification['startsAt'],
                    endsAt: $notification['endsAt'],
                ));
        }
    }
}
