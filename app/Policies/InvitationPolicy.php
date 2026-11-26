<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;

class InvitationPolicy
{
    public function resend(User $user, Invitation $invitation): bool
    {
        return $user->isAdminOf($invitation->team_id);
    }

    public function withdraw(User $user, Invitation $invitation): bool
    {
        return $user->isAdminOf($invitation->team_id);
    }
}
