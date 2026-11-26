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

    private function resolveActiveMembership(User $user, int $teamId): ?Membership
    {
        return $user->activeMemberships()
            ->where('team_id', $teamId)
            ->first();
    }
}
