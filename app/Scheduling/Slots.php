<?php

namespace App\Scheduling;

use App\Enums\MembershipStatus;
use App\Enums\ShiftStatus;
use App\Mail\SlotDeletedMail;
use App\Mail\SlotTimeChangedMail;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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
        [$startsAt, $endsAt] = $this->resolveTimestamps($date, $startTime, $endTime, $season->team->timezone);

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

            return new SlotActionResult(slot: $lockedSlot, updatedCount: 1);
        });
    }

    public function updateTimes(
        Slot $slot,
        User $actor,
        string $startTime,
        string $endTime,
        bool $acceptWarnings = false,
    ): SlotActionResult {
        /** @var array{result: SlotActionResult, notifications: list<array{recipient: User, slot: Slot, team: Team, oldStartsAt: CarbonImmutable, oldEndsAt: CarbonImmutable, newStartsAt: CarbonImmutable, newEndsAt: CarbonImmutable}>} $outcome */
        $outcome = DB::transaction(function () use ($slot, $actor, $startTime, $endTime, $acceptWarnings): array {
            $lockedSlot = Slot::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($slot->id);

            Gate::forUser($actor)->authorize('update', $lockedSlot);

            $team = $lockedSlot->season->team;
            $timezone = $team->timezone;
            $date = $lockedSlot->starts_at->setTimezone($timezone)->format('Y-m-d');

            [$newStartsLocal, $newEndsLocal] = $this->resolveTimestamps($date, $startTime, $endTime, $timezone);
            $newStartsAt = $newStartsLocal->utc();
            $newEndsAt = $newEndsLocal->utc();

            $oldStartsAt = $lockedSlot->starts_at;
            $oldEndsAt = $lockedSlot->ends_at;

            if ($newStartsAt->equalTo($oldStartsAt) && $newEndsAt->equalTo($oldEndsAt)) {
                return [
                    'result' => new SlotActionResult(slot: $lockedSlot, updatedCount: 0),
                    'notifications' => [],
                ];
            }

            $confirmedShifts = $lockedSlot->confirmedShifts()
                ->with('membership.user')
                ->get();

            $warnings = $this->checkConfirmedMemberOverlaps(
                team: $team,
                proposedSlots: [
                    $lockedSlot->id => [
                        'slot' => $lockedSlot,
                        'startsAt' => $newStartsAt,
                        'endsAt' => $newEndsAt,
                        'confirmedShifts' => array_values($confirmedShifts->all()),
                    ],
                ],
                errorField: 'times',
            );

            if ($warnings !== [] && ! $acceptWarnings) {
                return [
                    'result' => new SlotActionResult(warnings: $warnings),
                    'notifications' => [],
                ];
            }

            $lockedSlot->update([
                'starts_at' => $newStartsAt,
                'ends_at' => $newEndsAt,
            ]);

            $notifications = [];

            foreach ($confirmedShifts as $shift) {
                $recipient = $shift->membership->user;

                if ($recipient->id === $actor->id) {
                    continue;
                }

                $notifications[] = [
                    'recipient' => $recipient,
                    'slot' => $lockedSlot,
                    'team' => $team,
                    'oldStartsAt' => $oldStartsAt,
                    'oldEndsAt' => $oldEndsAt,
                    'newStartsAt' => $newStartsAt,
                    'newEndsAt' => $newEndsAt,
                ];
            }

            return [
                'result' => new SlotActionResult(slot: $lockedSlot, updatedCount: 1),
                'notifications' => $notifications,
            ];
        });

        $this->sendTimeChangedEmails($outcome['notifications']);

        return $outcome['result'];
    }

    public function delete(
        Slot $slot,
        User $actor,
        bool $notifyMembers = true,
    ): void {
        /** @var list<array{recipient: User, team: Team, startsAt: CarbonImmutable, endsAt: CarbonImmutable}> $notifications */
        $notifications = DB::transaction(function () use ($slot, $actor, $notifyMembers): array {
            $lockedSlot = Slot::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($slot->id);

            Gate::forUser($actor)->authorize('delete', $lockedSlot);

            if ($lockedSlot->hasStarted()) {
                throw ValidationException::withMessages([
                    'slot' => __('Slots that have already started cannot be deleted.'),
                ]);
            }

            $notifications = [];

            if ($notifyMembers) {
                $activeShifts = Shift::query()
                    ->where('slot_id', $lockedSlot->id)
                    ->whereIn('status', [ShiftStatus::Confirmed->value, ShiftStatus::Pending->value])
                    ->with('membership.user')
                    ->get();

                $team = $lockedSlot->season->team;

                foreach ($activeShifts as $shift) {
                    $recipient = $shift->membership->user;

                    if ($recipient->id === $actor->id) {
                        continue;
                    }

                    $notifications[] = [
                        'recipient' => $recipient,
                        'team' => $team,
                        'startsAt' => $lockedSlot->starts_at,
                        'endsAt' => $lockedSlot->ends_at,
                    ];
                }
            }

            $lockedSlot->delete();

            return $notifications;
        });

        $this->sendSlotDeletedEmails($notifications);
    }

    public function updateSeries(
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
            $this->resolveTimestamps('2027-01-01', $startTime, $endTime, $timezone);

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

                    [$newStartsLocal, $newEndsLocal] = $this->resolveTimestamps($date, $effectiveStart, $effectiveEnd, $timezone);
                    $newStartsAt = $newStartsLocal->utc();
                    $newEndsAt = $newEndsLocal->utc();

                    if (! $newStartsAt->equalTo($slot->starts_at) || ! $newEndsAt->equalTo($slot->ends_at)) {
                        $proposedTimeChanges[$slot->id] = [
                            'slot' => $slot,
                            'startsAt' => $newStartsAt,
                            'endsAt' => $newEndsAt,
                            'confirmedShifts' => array_values($slot->confirmedShifts->all()),
                        ];
                    }
                }

                if ($capacityChanged) {
                    $confirmedCount = $slot->confirmedShifts->count();

                    if ($capacity < $slot->capacity && $capacity < $confirmedCount) {
                        $slotLabel = $this->formatSlotDateTime($slot->starts_at, $slot->ends_at, $timezone);
                        $overCapacityWarnings[] = __(':date (:time) already has :confirmed confirmed shifts. Lowering capacity to :capacity will put it over capacity.', [
                            'date' => $slotLabel['date'],
                            'time' => $slotLabel['time'],
                            'confirmed' => $confirmedCount,
                            'capacity' => $capacity,
                        ]);
                    }
                }
            }

            $overlapWarnings = $this->checkConfirmedMemberOverlaps(
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

        $this->sendTimeChangedEmails($outcome['notifications']);

        return $outcome['result'];
    }

    public function deleteSeries(
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

        $this->sendSlotDeletedEmails($notifications);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveTimestamps(string $date, string $startTime, string $endTime, string $timezone): array
    {
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

        return [$startsAt, $endsAt];
    }

    /**
     * @param  array<int, array{slot: Slot, startsAt: CarbonImmutable, endsAt: CarbonImmutable, confirmedShifts: list<Shift>}>  $proposedSlots
     * @return list<string>
     */
    private function checkConfirmedMemberOverlaps(Team $team, array $proposedSlots, string $errorField): array
    {
        if ($proposedSlots === []) {
            return [];
        }

        $userIds = [];

        foreach ($proposedSlots as $entry) {
            foreach ($entry['confirmedShifts'] as $shift) {
                $userIds[$shift->membership->user_id] = true;
            }
        }

        if ($userIds === []) {
            return [];
        }

        $minStart = null;
        $maxEnd = null;

        foreach ($proposedSlots as $entry) {
            if ($minStart === null || $entry['startsAt']->lessThan($minStart)) {
                $minStart = $entry['startsAt'];
            }

            if ($maxEnd === null || $entry['endsAt']->greaterThan($maxEnd)) {
                $maxEnd = $entry['endsAt'];
            }
        }

        $editedSlotIds = array_keys($proposedSlots);

        $otherShiftsByUser = Shift::query()
            ->whereNotIn('slot_id', $editedSlotIds)
            ->whereIn('status', [ShiftStatus::Pending->value, ShiftStatus::Confirmed->value])
            ->whereHas('membership', function (Builder $query) use ($userIds): void {
                $query->whereIn('user_id', array_keys($userIds))
                    ->where('status', MembershipStatus::Active->value);
            })
            ->whereHas('slot', function (Builder $query) use ($minStart, $maxEnd): void {
                $query->where('starts_at', '<', $maxEnd)
                    ->where('ends_at', '>', $minStart);
            })
            ->with(['membership.team', 'slot'])
            ->get()
            ->sortBy(fn (Shift $shift): string => $shift->slot->starts_at->toIso8601String().'|'.$shift->slot->ends_at->toIso8601String().'|'.$shift->id)
            ->groupBy(fn (Shift $shift): int => $shift->membership->user_id);

        $crossTeamErrors = [];
        $sameTeamWarnings = [];

        foreach ($proposedSlots as $slotId => $entry) {
            $newStartsAt = $entry['startsAt'];
            $newEndsAt = $entry['endsAt'];

            foreach ($entry['confirmedShifts'] as $confirmedShift) {
                $memberUser = $confirmedShift->membership->user;

                foreach ($otherShiftsByUser->get($memberUser->id, collect()) as $otherShift) {
                    if (
                        $otherShift->slot->starts_at->greaterThanOrEqualTo($newEndsAt)
                        || $otherShift->slot->ends_at->lessThanOrEqualTo($newStartsAt)
                    ) {
                        continue;
                    }

                    $slotLabel = $this->formatSlotDateTime($otherShift->slot->starts_at, $otherShift->slot->ends_at, $team->timezone);

                    if ($otherShift->membership->team_id !== $team->id) {
                        $otherTeamName = $otherShift->membership->team->name;
                        $key = $memberUser->id.'|'.$otherShift->membership->team_id.'|'.$otherShift->slot_id;
                        $crossTeamErrors[$key] = __(':name already has an overlapping shift on :team (:date, :time).', [
                            'name' => $memberUser->name,
                            'team' => $otherTeamName,
                            'date' => $slotLabel['date'],
                            'time' => $slotLabel['time'],
                        ]);
                    } else {
                        $key = $slotId.'|'.$memberUser->id.'|'.$otherShift->slot_id;
                        $sameTeamWarnings[$key] = __("This slot overlaps with :name's shift on :date (:time).", [
                            'name' => $memberUser->name,
                            'date' => $slotLabel['date'],
                            'time' => $slotLabel['time'],
                        ]);
                    }
                }

                foreach ($proposedSlots as $otherSlotId => $otherEntry) {
                    if ($otherSlotId <= $slotId) {
                        continue;
                    }

                    $memberOnOtherSlot = false;

                    foreach ($otherEntry['confirmedShifts'] as $otherConfirmedShift) {
                        if ($otherConfirmedShift->membership->user_id === $memberUser->id) {
                            $memberOnOtherSlot = true;

                            break;
                        }
                    }

                    if (! $memberOnOtherSlot) {
                        continue;
                    }

                    if (
                        $otherEntry['startsAt']->greaterThanOrEqualTo($newEndsAt)
                        || $otherEntry['endsAt']->lessThanOrEqualTo($newStartsAt)
                    ) {
                        continue;
                    }

                    $slotLabel = $this->formatSlotDateTime($otherEntry['startsAt'], $otherEntry['endsAt'], $team->timezone);
                    $key = $slotId.'|'.$memberUser->id.'|'.$otherSlotId;
                    $sameTeamWarnings[$key] = __("This slot overlaps with :name's shift on :date (:time).", [
                        'name' => $memberUser->name,
                        'date' => $slotLabel['date'],
                        'time' => $slotLabel['time'],
                    ]);
                }
            }
        }

        if ($crossTeamErrors !== []) {
            throw ValidationException::withMessages([
                $errorField => array_values($crossTeamErrors),
            ]);
        }

        return array_values($sameTeamWarnings);
    }

    /**
     * @param  list<array{recipient: User, slot: Slot, team: Team, oldStartsAt: CarbonImmutable, oldEndsAt: CarbonImmutable, newStartsAt: CarbonImmutable, newEndsAt: CarbonImmutable}>  $notifications
     */
    private function sendTimeChangedEmails(array $notifications): void
    {
        foreach ($notifications as $notification) {
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
    }

    /**
     * @param  list<array{recipient: User, team: Team, startsAt: CarbonImmutable, endsAt: CarbonImmutable}>  $notifications
     */
    private function sendSlotDeletedEmails(array $notifications): void
    {
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

    /**
     * @return array{date: string, time: string}
     */
    private function formatSlotDateTime(CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $timezone): array
    {
        $startsLocal = $startsAt->setTimezone($timezone);
        $endsLocal = $endsAt->setTimezone($timezone);

        return [
            'date' => $startsLocal->translatedFormat('l j. F Y'),
            'time' => $startsLocal->format('H:i').'–'.$endsLocal->format('H:i'),
        ];
    }
}
