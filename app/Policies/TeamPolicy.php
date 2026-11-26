<?php

namespace App\Policies;

use App\Enums\MembershipRole;
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
        return $user->activeMemberships()->whereBelongsTo($team)->where('role', MembershipRole::Admin)->exists();
    }
}
