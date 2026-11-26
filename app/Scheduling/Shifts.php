<?php

namespace App\Scheduling;

use App\Enums\ShiftStatus;
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

            if ($requiresConfirmation && $confirmedCount >= $lockedSlot->capacity && ! $acceptWarnings) {
                return new ShiftActionResult(warnings: [
                    __('This slot is already full. You can still send a request, and an admin will decide if you get a place.'),
                ]);
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

    public function confirm(Shift $shift, User $actor): Shift
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

            Gate::forUser($actor)->authorize('confirm', $lockedShift);

            if ($lockedShift->status !== ShiftStatus::Pending) {
                throw ValidationException::withMessages([
                    'shift' => __('Only pending shifts can be confirmed.'),
                ]);
            }

            $confirmedCount = Shift::query()
                ->where('slot_id', $lockedSlot->id)
                ->where('status', ShiftStatus::Confirmed)
                ->count();

            if ($confirmedCount >= $lockedSlot->capacity) {
                throw ValidationException::withMessages([
                    'shift' => __('This slot is full.'),
                ]);
            }

            $lockedShift->update([
                'status' => ShiftStatus::Confirmed,
                'confirmed_at' => now(),
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
                ->send(new ShiftConfirmedMail($result['shift'], $result['slot'], $result['slot']->season->team));
        }

        return $result['shift'];
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

    private function resolveActiveMembership(User $user, int $teamId): ?Membership
    {
        return $user->activeMemberships()
            ->where('team_id', $teamId)
            ->first();
    }
}
