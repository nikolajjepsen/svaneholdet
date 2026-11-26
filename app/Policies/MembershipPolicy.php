<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    public function promote(User $user, Membership $membership): bool
    {
        return $this->isTeamAdmin($user, $membership);
    }

    public function demote(User $user, Membership $membership): bool
    {
        return $this->isTeamAdmin($user, $membership);
    }

    public function remove(User $user, Membership $membership): bool
    {
        return $this->isTeamAdmin($user, $membership);
    }

    private function isTeamAdmin(User $user, Membership $membership): bool
    {
        return $user->activeMemberships()
            ->where('team_id', $membership->team_id)
            ->where('role', MembershipRole::Admin)
            ->exists();
    }
}
