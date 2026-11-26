<?php

namespace App\Auth;

use App\Models\Team;
use App\Models\User;

class CurrentTeam
{
    public function resolve(User $user): ?Team
    {
        return $user->activeMemberships()
            ->with('team')
            ->oldest()
            ->orderBy('id')
            ->first()?->team;
    }
}
