<?php

namespace App\Http\Middleware;

use App\Auth\CurrentTeam;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentTeam
{
    public function __construct(private CurrentTeam $currentTeam) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $activeTeams = $this->currentTeam->activeTeams($user);
        $team = $this->currentTeam->resolve($user, $activeTeams);

        if ($team === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login');
        }

        Gate::authorize('view', $team);

        $request->attributes->set(Team::class, $team);
        $request->attributes->set(CurrentTeam::ACTIVE_TEAMS_ATTRIBUTE, $activeTeams);

        return $next($request);
    }
}
