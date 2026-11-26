<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->activeMemberships()->whereBelongsTo($team)->exists();
    }

    public function invite(User $user, Team $team): bool
    {
        return $user->isAdminOf($team);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $user->isAdminOf($team);
    }

    public function updateSettings(User $user, Team $team): bool
    {
        return $user->isAdminOf($team);
    }
}
