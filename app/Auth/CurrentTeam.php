<?php

namespace App\Auth;

use App\Enums\MembershipStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class CurrentTeam
{
    public const string SESSION_KEY = 'current_team_id';

    /** @return Collection<int, Team> */
    public function activeTeams(User $user): Collection
    {
        return Team::query()
            ->select('teams.*')
            ->join('memberships', 'memberships.team_id', '=', 'teams.id')
            ->where('memberships.user_id', $user->id)
            ->where('memberships.status', MembershipStatus::Active->value)
            ->orderBy('memberships.created_at')
            ->orderBy('memberships.id')
            ->get();
    }

    public function resolve(User $user): ?Team
    {
        $teams = $this->activeTeams($user);

        if ($teams->isEmpty()) {
            return null;
        }

        $sessionTeamId = $this->sessionTeamId();

        if ($sessionTeamId !== null) {
            $team = $teams->firstWhere('id', $sessionTeamId);

            if ($team !== null) {
                return $team;
            }

            session()->forget(self::SESSION_KEY);
        }

        if ($user->last_chosen_team_id !== null) {
            $team = $teams->firstWhere('id', $user->last_chosen_team_id);

            if ($team !== null) {
                return $team;
            }
        }

        return $teams->first();
    }

    public function switchTo(User $user, Team $team): void
    {
        Gate::forUser($user)->authorize('view', $team);

        session()->put(self::SESSION_KEY, $team->id);

        $user->last_chosen_team_id = $team->id;
        $user->save();
    }

    private function sessionTeamId(): ?int
    {
        $value = session()->get(self::SESSION_KEY);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
