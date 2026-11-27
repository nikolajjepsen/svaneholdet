<?php

namespace App\Scheduling;

use App\Enums\ShiftStatus;
use App\Mail\SlotDeletedMail;
use App\Mail\SlotTimeChangedMail;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class Slots
{
    public function __construct(
        private readonly ShiftOverlaps $overlaps,
    ) {}

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
        $window = SlotWindow::fromLocal($date, $startTime, $endTime, $season->team->timezone);

        return Slot::query()->create([
            'season_id' => $season->id,
            'starts_at' => $window->startsAt,
            'ends_at' => $window->endsAt,
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

            $confirmedCount = $lockedSlot->confirmedCapacityCount();

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

            $window = SlotWindow::fromLocal($date, $startTime, $endTime, $timezone);
            $oldStartsAt = $lockedSlot->starts_at;
            $oldEndsAt = $lockedSlot->ends_at;

            if ($window->startsAt->equalTo($oldStartsAt) && $window->endsAt->equalTo($oldEndsAt)) {
                return [
                    'result' => new SlotActionResult(slot: $lockedSlot, updatedCount: 0),
                    'notifications' => [],
                ];
            }

            $confirmedShifts = $lockedSlot->confirmedShifts()
                ->with('membership.user')
                ->get();

            $warnings = $this->overlaps->checkForSlotChanges(
                team: $team,
                proposedSlots: [
                    $lockedSlot->id => [
                        'slot' => $lockedSlot,
                        'startsAt' => $window->startsAt,
                        'endsAt' => $window->endsAt,
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
                'starts_at' => $window->startsAt,
                'ends_at' => $window->endsAt,
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
                    'newStartsAt' => $window->startsAt,
                    'newEndsAt' => $window->endsAt,
                ];
            }

            return [
                'result' => new SlotActionResult(slot: $lockedSlot, updatedCount: 1),
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
