<?php

namespace App\Scheduling;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\SeasonHourGoal;
use App\Models\TeamSettings;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class HourGoals
{
    public static function effective(TeamSettings $settings, ?SeasonHourGoal $override): ?int
    {
        return match ($override?->hours) {
            null => $settings->defaultHourGoal,
            0 => null,
            default => $override->hours,
        };
    }

    public function setOverride(Season $season, Membership $membership, User $actor, ?int $hours): void
    {
        Gate::forUser($actor)->authorize('update', $season);
        abort_unless($membership->team_id === $season->team_id && $membership->status === MembershipStatus::Active, 403);

        if ($hours === null) {
            $season->hourGoals()->whereBelongsTo($membership)->delete();

            return;
        }

        $season->hourGoals()->upsert(
            [['membership_id' => $membership->id, 'hours' => $hours]],
            uniqueBy: ['season_id', 'membership_id'],
            update: ['hours'],
        );
    }
}
