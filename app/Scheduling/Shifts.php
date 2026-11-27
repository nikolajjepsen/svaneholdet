<?php

namespace App\Scheduling;

use App\Enums\MembershipStatus;
use App\Enums\ShiftStatus;
use App\Mail\ShiftCancelledMail;
use App\Mail\ShiftConfirmedMail;
use App\Mail\ShiftRejectedMail;
use App\Models\Membership;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\User;
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

            $requiresConfirmation = $lockedSlot->season->team->settings->shiftsRequireConfirmation;

            $confirmedCount = Shift::query()
                ->where('slot_id', $lockedSlot->id)
                ->where('status', ShiftStatus::Confirmed)
                ->count();

            if (! $requiresConfirmation && $confirmedCount >= $lockedSlot->capacity) {
                throw ValidationException::withMessages([
                    'slot' => __('This slot is full.'),
                ]);
            }

            $warnings = [];

            if ($requiresConfirmation && $confirmedCount >= $lockedSlot->capacity) {
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

            $confirmedCount = Shift::query()
                ->where('slot_id', $lockedSlot->id)
                ->where('status', ShiftStatus::Confirmed)
                ->count();

            $warnings = [];

            if ($confirmedCount >= $lockedSlot->capacity) {
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

            $confirmedCount = Shift::query()
                ->where('slot_id', $lockedSlot->id)
                ->where('status', ShiftStatus::Confirmed)
                ->count();

            $warnings = [];

            if ($confirmedCount >= $lockedSlot->capacity) {
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

    private function resolveActiveMembership(User $user, int $teamId): ?Membership
    {
        return $user->activeMemberships()
            ->where('team_id', $teamId)
            ->first();
    }
}
