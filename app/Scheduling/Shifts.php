<?php

namespace App\Scheduling;

use App\Enums\AttendanceStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\ShiftStatus;
use App\Mail\ShiftCancelledMail;
use App\Mail\ShiftConfirmedMail;
use App\Mail\ShiftRejectedMail;
use App\Models\Membership;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class Shifts
{
    public function __construct(
        private readonly ShiftOverlaps $overlaps,
    ) {}

    public function signUp(Slot $slot, User $actor, bool $acceptWarnings = false): ShiftActionResult
    {
        return DB::transaction(function () use ($slot, $actor, $acceptWarnings): ShiftActionResult {
            $lockedSlot = Slot::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($slot->id);

            Gate::forUser($actor)->authorize('signUp', $lockedSlot);

            $membership = $this->resolveActiveMembership($actor, $lockedSlot->season->team_id);

            if ($membership === null) {
                throw ValidationException::withMessages([
                    'slot' => __('You are not an active member of this team.'),
                ]);
            }

            $alreadySignedUp = Shift::query()
                ->where('slot_id', $lockedSlot->id)
                ->where('membership_id', $membership->id)
                ->whereIn('status', [ShiftStatus::Pending->value, ShiftStatus::Confirmed->value])
                ->exists();

            if ($alreadySignedUp) {
                throw ValidationException::withMessages([
                    'slot' => __('You are already signed up for this slot.'),
                ]);
            }

            $requiresConfirmation = $membership->role !== MembershipRole::Admin
                && $lockedSlot->season->team->settings->shiftsRequireConfirmation;

            $isFull = $membership->role === $lockedSlot->capacityRole()
                && $lockedSlot->confirmedCapacityCount() >= $lockedSlot->capacity;

            if (! $requiresConfirmation && $isFull) {
                throw ValidationException::withMessages([
                    'slot' => __('This slot is full.'),
                ]);
            }

            $warnings = [];

            if ($requiresConfirmation && $isFull) {
                $warnings[] = __('This slot is already full. You can still send a request, and an admin will decide if you get a place.');
            }

            $warnings = [
                ...$warnings,
                ...$this->overlaps->checkForMember($actor, $lockedSlot, errorField: 'slot'),
            ];

            if ($warnings !== [] && ! $acceptWarnings) {
                return new ShiftActionResult(warnings: $warnings);
            }

            $shift = Shift::query()->create([
                'slot_id' => $lockedSlot->id,
                'membership_id' => $membership->id,
                'status' => $requiresConfirmation ? ShiftStatus::Pending : ShiftStatus::Confirmed,
                'confirmed_at' => $requiresConfirmation ? null : now(),
            ]);

            return new ShiftActionResult(shift: $shift);
        });
    }

    public function assign(
        Slot $slot,
        Membership $membership,
        User $actor,
        bool $acceptWarnings = false,
    ): ShiftActionResult {
        return DB::transaction(function () use ($slot, $membership, $actor, $acceptWarnings): ShiftActionResult {
            $lockedSlot = Slot::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($slot->id);

            Gate::forUser($actor)->authorize('assign', $lockedSlot);

            $targetMembership = Membership::query()
                ->with('user')
                ->findOrFail($membership->id);

            if ($targetMembership->team_id !== $lockedSlot->season->team_id) {
                throw ValidationException::withMessages([
                    'assignMembershipId' => __('This member does not belong to this team.'),
                ]);
            }

            if ($targetMembership->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages([
                    'assignMembershipId' => __('This member is no longer active on the team.'),
                ]);
            }

            $alreadySignedUp = Shift::query()
                ->where('slot_id', $lockedSlot->id)
                ->where('membership_id', $targetMembership->id)
                ->whereIn('status', [ShiftStatus::Pending->value, ShiftStatus::Confirmed->value])
                ->exists();

            if ($alreadySignedUp) {
                throw ValidationException::withMessages([
                    'assignMembershipId' => __('This member is already signed up for this slot.'),
                ]);
            }

            $overlapWarnings = $this->overlaps->checkForMember(
                $targetMembership->user,
                $lockedSlot,
                errorField: 'assignMembershipId',
                memberName: $targetMembership->user->name,
            );

            $confirmedCount = $lockedSlot->confirmedCapacityCount();

            $warnings = [];

            if ($targetMembership->role === $lockedSlot->capacityRole() && $confirmedCount >= $lockedSlot->capacity) {
                $warnings[] = __('This slot is already full (:confirmed / :capacity). Assigning :name will put it over capacity.', [
                    'confirmed' => $confirmedCount,
                    'capacity' => $lockedSlot->capacity,
                    'name' => $targetMembership->user->name,
                ]);
            }

            $warnings = [...$warnings, ...$overlapWarnings];

            if ($warnings !== [] && ! $acceptWarnings) {
                return new ShiftActionResult(warnings: $warnings);
            }

            $shift = Shift::query()->create([
                'slot_id' => $lockedSlot->id,
                'membership_id' => $targetMembership->id,
                'status' => ShiftStatus::Confirmed,
                'confirmed_at' => now(),
            ]);

            return new ShiftActionResult(shift: $shift);
        });
    }

    public function confirm(Shift $shift, User $actor, bool $acceptWarnings = false): ShiftActionResult
    {
        /** @var array{result: ShiftActionResult, slot?: Slot, recipient?: User} $outcome */
        $outcome = DB::transaction(function () use ($shift, $actor, $acceptWarnings): array {
            $lockedSlot = Slot::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($shift->slot_id);

            $lockedShift = Shift::query()
                ->with('membership.user')
                ->lockForUpdate()
                ->findOrFail($shift->id);

            if ($lockedShift->membership->team_id !== $lockedSlot->season->team_id) {
                throw ValidationException::withMessages([
                    'shift' => __('This shift does not belong to this team.'),
                ]);
            }

            Gate::forUser($actor)->authorize('confirm', $lockedShift);

            if ($lockedShift->status !== ShiftStatus::Pending) {
                throw ValidationException::withMessages([
                    'shift' => __('Only pending shifts can be confirmed.'),
                ]);
            }

            $overlapWarnings = $this->overlaps->checkForMember(
                $lockedShift->membership->user,
                $lockedSlot,
                errorField: 'shift',
                memberName: $lockedShift->membership->user->name,
            );

            $confirmedCount = $lockedSlot->confirmedCapacityCount();

            $warnings = [];

            if ($lockedShift->membership->role === $lockedSlot->capacityRole() && $confirmedCount >= $lockedSlot->capacity) {
                $warnings[] = __('This slot is already full (:confirmed / :capacity). Confirming :name will put it over capacity.', [
                    'confirmed' => $confirmedCount,
                    'capacity' => $lockedSlot->capacity,
                    'name' => $lockedShift->membership->user->name,
                ]);
            }

            $warnings = [...$warnings, ...$overlapWarnings];

            if ($warnings !== [] && ! $acceptWarnings) {
                return [
                    'result' => new ShiftActionResult(warnings: $warnings),
                ];
            }

            $lockedShift->update([
                'status' => ShiftStatus::Confirmed,
                'confirmed_at' => now(),
            ]);

            return [
                'result' => new ShiftActionResult(shift: $lockedShift),
                'slot' => $lockedSlot,
                'recipient' => $lockedShift->membership->user,
            ];
        });

        if (
            ! $outcome['result']->hasWarnings()
            && $outcome['result']->shift !== null
            && isset($outcome['recipient'], $outcome['slot'])
            && $outcome['recipient']->id !== $actor->id
        ) {
            Mail::to($outcome['recipient']->email)
                ->locale($outcome['recipient']->preferredLocale())
                ->send(new ShiftConfirmedMail($outcome['result']->shift, $outcome['slot'], $outcome['slot']->season->team));
        }

        return $outcome['result'];
    }

    public function reject(Shift $shift, User $actor): Shift
    {
        /** @var array{shift: Shift, slot: Slot, recipient: User} $result */
        $result = DB::transaction(function () use ($shift, $actor): array {
            $lockedSlot = Slot::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($shift->slot_id);

            $lockedShift = Shift::query()
                ->with('membership.user')
                ->lockForUpdate()
                ->findOrFail($shift->id);

            if ($lockedShift->membership->team_id !== $lockedSlot->season->team_id) {
                throw ValidationException::withMessages([
                    'shift' => __('This shift does not belong to this team.'),
                ]);
            }

            Gate::forUser($actor)->authorize('reject', $lockedShift);

            if ($lockedShift->status !== ShiftStatus::Pending) {
                throw ValidationException::withMessages([
                    'shift' => __('Only pending shifts can be rejected.'),
                ]);
            }

            $lockedShift->update([
                'status' => ShiftStatus::Rejected,
            ]);

            return [
                'shift' => $lockedShift,
                'slot' => $lockedSlot,
                'recipient' => $lockedShift->membership->user,
            ];
        });

        if ($result['recipient']->id !== $actor->id) {
            Mail::to($result['recipient']->email)
                ->locale($result['recipient']->preferredLocale())
                ->send(new ShiftRejectedMail($result['shift'], $result['slot'], $result['slot']->season->team));
        }

        return $result['shift'];
    }

    public function cancel(Shift $shift, User $actor): Shift
    {
        return DB::transaction(function () use ($shift, $actor): Shift {
            $lockedShift = Shift::query()
                ->with(['membership', 'slot.season.team'])
                ->lockForUpdate()
                ->findOrFail($shift->id);

            Gate::forUser($actor)->authorize('cancel', $lockedShift);

            if ($lockedShift->status !== ShiftStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'shift' => __('Only confirmed shifts can be cancelled.'),
                ]);
            }

            if (! $lockedShift->slot->isBeforeCancellationDeadline($lockedShift->slot->season->team)) {
                throw ValidationException::withMessages([
                    'shift' => __('The cancellation deadline has passed. Contact an admin to cancel this shift.'),
                ]);
            }

            $lockedShift->status = ShiftStatus::Cancelled;
            $this->clearAttendanceFields($lockedShift);
            $lockedShift->save();

            return $lockedShift;
        });
    }

    public function cancelByAdmin(Shift $shift, User $actor): Shift
    {
        /** @var array{shift: Shift, slot: Slot, recipient: User} $result */
        $result = DB::transaction(function () use ($shift, $actor): array {
            $lockedSlot = Slot::query()
                ->with('season.team')
                ->lockForUpdate()
                ->findOrFail($shift->slot_id);

            $lockedShift = Shift::query()
                ->with('membership.user')
                ->lockForUpdate()
                ->findOrFail($shift->id);

            if ($lockedShift->membership->team_id !== $lockedSlot->season->team_id) {
                throw ValidationException::withMessages([
                    'shift' => __('This shift does not belong to this team.'),
                ]);
            }

            Gate::forUser($actor)->authorize('cancelByAdmin', $lockedShift);

            if ($lockedShift->status !== ShiftStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'shift' => __('Only confirmed shifts can be cancelled.'),
                ]);
            }

            $lockedShift->status = ShiftStatus::Cancelled;
            $this->clearAttendanceFields($lockedShift);
            $lockedShift->save();

            return [
                'shift' => $lockedShift,
                'slot' => $lockedSlot,
                'recipient' => $lockedShift->membership->user,
            ];
        });

        if ($result['recipient']->id !== $actor->id) {
            Mail::to($result['recipient']->email)
                ->locale($result['recipient']->preferredLocale())
                ->send(new ShiftCancelledMail($result['shift'], $result['slot'], $result['slot']->season->team));
        }

        return $result['shift'];
    }

    public function adjustAttendance(
        Shift $shift,
        User $actor,
        string $startTime,
        string $endTime,
        string $startErrorKey = 'actualStartTime',
        string $endErrorKey = 'actualEndTime',
    ): Shift {
        return DB::transaction(function () use ($shift, $actor, $startTime, $endTime, $startErrorKey, $endErrorKey): Shift {
            [$lockedSlot, $lockedShift] = $this->lockConfirmedShiftForAttendance($shift, $actor);

            $window = SlotWindow::fromActualTimes(
                plannedStartsAt: $lockedSlot->starts_at,
                startTime: $startTime,
                endTime: $endTime,
                timezone: $lockedSlot->season->team->timezone,
                startErrorKey: $startErrorKey,
                endErrorKey: $endErrorKey,
            );

            $lockedShift->update([
                'attendance_status' => AttendanceStatus::Adjusted,
                'actual_starts_at' => $window->startsAt,
                'actual_ends_at' => $window->endsAt,
                'attendance_recorded_by' => $actor->id,
                'attendance_recorded_at' => now(),
            ]);

            return $lockedShift;
        });
    }

    public function markNoShow(Shift $shift, User $actor): Shift
    {
        return DB::transaction(function () use ($shift, $actor): Shift {
            [, $lockedShift] = $this->lockConfirmedShiftForAttendance($shift, $actor);

            $lockedShift->update([
                'attendance_status' => AttendanceStatus::NoShow,
                'actual_starts_at' => null,
                'actual_ends_at' => null,
                'attendance_recorded_by' => $actor->id,
                'attendance_recorded_at' => now(),
            ]);

            return $lockedShift;
        });
    }

    public function resetAttendance(Shift $shift, User $actor): Shift
    {
        return DB::transaction(function () use ($shift, $actor): Shift {
            [, $lockedShift] = $this->lockConfirmedShiftForAttendance($shift, $actor);

            $this->clearAttendanceFields($lockedShift);
            $lockedShift->save();

            return $lockedShift;
        });
    }

    public function withdraw(Shift $shift, User $actor): Shift
    {
        return DB::transaction(function () use ($shift, $actor): Shift {
            $lockedShift = Shift::query()
                ->with('membership')
                ->lockForUpdate()
                ->findOrFail($shift->id);

            Gate::forUser($actor)->authorize('withdraw', $lockedShift);

            if ($lockedShift->status !== ShiftStatus::Pending) {
                throw ValidationException::withMessages([
                    'shift' => __('Only pending shifts can be withdrawn.'),
                ]);
            }

            $lockedShift->status = ShiftStatus::Cancelled;
            $lockedShift->save();

            return $lockedShift;
        });
    }

    public function cancelUpcomingForMembership(Membership $membership): void
    {
        $upcomingShifts = Shift::query()
            ->where('membership_id', $membership->id)
            ->whereIn('status', [ShiftStatus::Pending->value, ShiftStatus::Confirmed->value])
            ->whereHas('slot', fn (Builder $query): Builder => $query->where('starts_at', '>', now()))
            ->get();

        foreach ($upcomingShifts as $shift) {
            $shift->status = ShiftStatus::Cancelled;
            $this->clearAttendanceFields($shift);
            $shift->save();
        }
    }

    /**
     * @return array{0: Slot, 1: Shift}
     */
    private function lockConfirmedShiftForAttendance(Shift $shift, User $actor): array
    {
        $lockedSlot = Slot::query()
            ->with('season.team')
            ->lockForUpdate()
            ->findOrFail($shift->slot_id);

        $lockedShift = Shift::query()
            ->with('membership')
            ->lockForUpdate()
            ->findOrFail($shift->id);

        if ($lockedShift->membership->team_id !== $lockedSlot->season->team_id) {
            throw ValidationException::withMessages([
                'attendance' => __('This shift does not belong to this team.'),
            ]);
        }

        Gate::forUser($actor)->authorize('recordAttendance', $lockedShift);

        if ($lockedShift->status !== ShiftStatus::Confirmed) {
            throw ValidationException::withMessages([
                'attendance' => __('Attendance can only be edited on confirmed shifts.'),
            ]);
        }

        return [$lockedSlot, $lockedShift];
    }

    private function clearAttendanceFields(Shift $shift): void
    {
        $shift->attendance_status = AttendanceStatus::Planned;
        $shift->actual_starts_at = null;
        $shift->actual_ends_at = null;
        $shift->attendance_recorded_by = null;
        $shift->attendance_recorded_at = null;
    }

    private function resolveActiveMembership(User $user, int $teamId): ?Membership
    {
        return $user->activeMemberships()
            ->where('team_id', $teamId)
            ->first();
    }
}
