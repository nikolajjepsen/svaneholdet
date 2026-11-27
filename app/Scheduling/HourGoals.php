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

    public static function effectiveForMember(TeamSettings $settings, Season $season, Membership $membership): ?int
    {
        return self::effective($settings, $season->hourGoals()->whereBelongsTo($membership)->first());
    }

    public static function label(TeamSettings $settings, ?SeasonHourGoal $override): string
    {
        $effectiveGoal = self::effective($settings, $override);

        return match (true) {
            $override?->hours === 0 => __('Exempt'),
            $effectiveGoal === null => __('No hour goal'),
            $override === null => __(':hours h (team default)', ['hours' => $effectiveGoal]),
            default => __(':hours h (custom)', ['hours' => $effectiveGoal]),
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
