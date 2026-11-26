<?php

namespace App\Http\Controllers;

use App\Auth\CurrentTeam;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentTeamController
{
    public function __invoke(Request $request, CurrentTeam $currentTeam): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $request->validate([
            'team_id' => ['required', 'integer'],
        ]);

        $team = Team::query()->find($request->integer('team_id'));
        abort_if($team === null, 403);

        $currentTeam->switchTo($user, $team);

        return to_route('dashboard');
    }
}
