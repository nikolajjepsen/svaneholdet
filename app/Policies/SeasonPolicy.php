<?php

namespace App\Policies;

use App\Models\Season;
use App\Models\Team;
use App\Models\User;

class SeasonPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->activeMemberships()->whereBelongsTo($team)->exists();
    }

    public function view(User $user, Season $season): bool
    {
        return $user->activeMemberships()->where('team_id', $season->team_id)->exists();
    }

    public function create(User $user, Team $team): bool
    {
        return $user->isAdminOf($team);
    }

    public function update(User $user, Season $season): bool
    {
        return $user->isAdminOf($season->team_id);
    }

    public function delete(User $user, Season $season): bool
    {
        return $user->isAdminOf($season->team_id);
    }
}
