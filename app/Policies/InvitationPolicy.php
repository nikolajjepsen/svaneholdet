<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Invitation;
use App\Models\User;

class InvitationPolicy
{
    public function resend(User $user, Invitation $invitation): bool
    {
        return $this->isTeamAdmin($user, $invitation);
    }

    public function withdraw(User $user, Invitation $invitation): bool
    {
        return $this->isTeamAdmin($user, $invitation);
    }

    private function isTeamAdmin(User $user, Invitation $invitation): bool
    {
        return $user->activeMemberships()
            ->where('team_id', $invitation->team_id)
            ->where('role', MembershipRole::Admin)
            ->exists();
    }
}
