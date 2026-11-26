<?php

namespace App\Scheduling;

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Shifts
{
    public function signUp(Slot $slot, User $actor): Shift
    {
        return DB::transaction(function () use ($slot, $actor): Shift {
            $lockedSlot = Slot::query()
                ->with('season')
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

            $confirmedCount = Shift::query()
                ->where('slot_id', $lockedSlot->id)
                ->where('status', ShiftStatus::Confirmed)
                ->count();

            if ($confirmedCount >= $lockedSlot->capacity) {
                throw ValidationException::withMessages([
                    'slot' => __('This slot is full.'),
                ]);
            }

            return Shift::query()->create([
                'slot_id' => $lockedSlot->id,
                'membership_id' => $membership->id,
                'status' => ShiftStatus::Confirmed,
                'confirmed_at' => now(),
            ]);
        });
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
