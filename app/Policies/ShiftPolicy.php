<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->activeMemberships()->whereBelongsTo($team)->exists();
    }

    public function view(User $user, Shift $shift): bool
    {
        $teamId = $shift->membership->team_id;

        if ($shift->membership->user_id === $user->id) {
            return $user->activeMemberships()->where('team_id', $teamId)->exists();
        }

        return $user->isAdminOf($teamId);
    }

    public function create(User $user, Slot $slot): bool
    {
        return $user->activeMemberships()->where('team_id', $slot->season->team_id)->exists();
    }

    public function update(User $user, Shift $shift): bool
    {
        return $user->isAdminOf($shift->membership->team_id);
    }

    public function delete(User $user, Shift $shift): bool
    {
        return $user->isAdminOf($shift->membership->team_id);
    }

    public function confirm(User $user, Shift $shift): bool
    {
        return $user->isAdminOf($shift->membership->team_id);
    }

    public function reject(User $user, Shift $shift): bool
    {
        return $user->isAdminOf($shift->membership->team_id);
    }

    public function cancel(User $user, Shift $shift): bool
    {
        if ($shift->membership->user_id !== $user->id) {
            return false;
        }

        return $user->activeMemberships()->where('team_id', $shift->membership->team_id)->exists();
    }

    public function withdraw(User $user, Shift $shift): bool
    {
        if ($shift->membership->user_id !== $user->id) {
            return false;
        }

        return $user->activeMemberships()->where('team_id', $shift->membership->team_id)->exists();
    }
}
