<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    public function promote(User $user, Membership $membership): bool
    {
        return $user->isAdminOf($membership->team_id);
    }

    public function demote(User $user, Membership $membership): bool
    {
        return $user->isAdminOf($membership->team_id);
    }

    public function remove(User $user, Membership $membership): bool
    {
        return $user->isAdminOf($membership->team_id);
    }
}
