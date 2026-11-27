<?php

namespace App\Policies;

use App\Models\Season;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;

class SlotSeriesPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->isAdminOf($team);
    }

    public function view(User $user, SlotSeries $slotSeries): bool
    {
        return $user->isAdminOf($slotSeries->season->team_id);
    }

    public function create(User $user, Season $season): bool
    {
        return $user->isAdminOf($season->team_id);
    }

    public function update(User $user, SlotSeries $slotSeries): bool
    {
        return $user->isAdminOf($slotSeries->season->team_id);
    }

    public function delete(User $user, SlotSeries $slotSeries): bool
    {
        return $user->isAdminOf($slotSeries->season->team_id);
    }
}
