<?php

namespace App\Policies;

use App\Models\Season;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;

class SlotPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->activeMemberships()->whereBelongsTo($team)->exists();
    }

    public function view(User $user, Slot $slot): bool
    {
        return $slot->is_admin_only
            ? $user->isAdminOf($slot->season->team_id)
            : $user->activeMemberships()->where('team_id', $slot->season->team_id)->exists();
    }

    public function create(User $user, Season $season): bool
    {
        return $user->isAdminOf($season->team_id);
    }

    public function update(User $user, Slot $slot): bool
    {
        return $user->isAdminOf($slot->season->team_id);
    }

    public function delete(User $user, Slot $slot): bool
    {
        return $user->isAdminOf($slot->season->team_id);
    }

    public function manage(User $user, Slot $slot): bool
    {
        return $user->isAdminOf($slot->season->team_id);
    }

    public function assign(User $user, Slot $slot): bool
    {
        return $user->isAdminOf($slot->season->team_id);
    }

    public function signUp(User $user, Slot $slot): bool
    {
        return $this->view($user, $slot);
    }
}
