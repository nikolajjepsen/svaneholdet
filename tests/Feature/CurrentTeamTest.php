<?php

use App\Enums\MembershipStatus;
use App\Mail\LoginLinkMail;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('resolves the current team from session, else last chosen team, else earliest active membership', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $earliestTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $secondTeam = Team::factory()->create(['name' => 'Beta Team']);
    $thirdTeam = Team::factory()->create(['name' => 'Gamma Team']);

    Membership::factory()->for($user)->for($earliestTeam)->create(['created_at' => now()->subDays(3)]);
    Membership::factory()->for($user)->for($secondTeam)->create(['created_at' => now()->subDays(2)]);
    Membership::factory()->for($user)->for($thirdTeam)->create(['created_at' => now()->subDay()]);

    // Earliest active membership when neither session nor last chosen team is set.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Alpha Team');

    // Last chosen team takes precedence over earliest active membership.
    $user->last_chosen_team_id = $secondTeam->id;
    $user->save();

    $this->actingAs($user->refresh())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Beta Team');

    // Session takes precedence over last chosen team.
    $this->actingAs($user)
        ->withSession(['current_team_id' => $thirdTeam->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Gamma Team');
});

it('switches team, saves it as last chosen, and opens it after a new login', function () {
    Mail::fake();
    $user = User::factory()->create(['locale' => 'en']);
    $teamA = Team::factory()->create(['name' => 'Team Alpha']);
    $teamB = Team::factory()->create(['name' => 'Team Beta']);

    Membership::factory()->for($user)->for($teamA)->create(['created_at' => now()->subDays(2)]);
    Membership::factory()->for($user)->for($teamB)->create(['created_at' => now()->subDay()]);

    $this->actingAs($user)
        ->post(route('current-team.update'), ['team_id' => $teamB->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('current_team_id', $teamB->id);

    expect($user->refresh()->last_chosen_team_id)->toBe($teamB->id);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Team Beta');

    // Log out and log back in via login link on a fresh session.
    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->post(route('login.request'), ['email' => $user->email])->assertRedirect(route('login'));

    $url = Mail::sent(LoginLinkMail::class)->sole()->url;
    $this->post(parse_url($url, PHP_URL_PATH))->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Team Beta');
});

it('refuses switching to a team where the user has no active membership', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $activeTeam = Team::factory()->create(['name' => 'Active Team']);
    $otherTeam = Team::factory()->create(['name' => 'Other Team']);
    $removedTeam = Team::factory()->create(['name' => 'Removed Team']);

    Membership::factory()->for($user)->for($activeTeam)->create();
    Membership::factory()->for($user)->for($removedTeam)->removed()->create();

    $this->actingAs($user)
        ->post(route('current-team.update'), ['team_id' => $otherTeam->id])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('current-team.update'), ['team_id' => $removedTeam->id])
        ->assertForbidden();

    expect($user->refresh()->last_chosen_team_id)->toBeNull();
});

it('falls back to another active team when the current team membership is removed', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $earliestTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $secondTeam = Team::factory()->create(['name' => 'Beta Team']);
    $thirdTeam = Team::factory()->create(['name' => 'Gamma Team']);

    Membership::factory()->for($user)->for($earliestTeam)->create(['created_at' => now()->subDays(3)]);
    $secondMembership = Membership::factory()->for($user)->for($secondTeam)->create(['created_at' => now()->subDays(2)]);
    $thirdMembership = Membership::factory()->for($user)->for($thirdTeam)->create(['created_at' => now()->subDay()]);

    $user->last_chosen_team_id = $secondTeam->id;
    $user->save();

    // Session points to Gamma, last chosen is Beta. Remove Gamma -> falls back to Beta.
    $thirdMembership->update(['status' => MembershipStatus::Removed]);

    $this->actingAs($user->refresh())
        ->withSession(['current_team_id' => $thirdTeam->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Beta Team')
        ->assertDontSee('Gamma Team');

    // Now remove Beta (the last chosen team too) -> falls back to Alpha (earliest remaining active).
    $secondMembership->update(['status' => MembershipStatus::Removed]);

    $this->actingAs($user->refresh())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Alpha Team')
        ->assertDontSee('Beta Team');
});

it('logs out a user whose last remaining active membership is removed', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $team = Team::factory()->create(['name' => 'Solo Team']);
    $membership = Membership::factory()->for($user)->for($team)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Solo Team');

    $membership->update(['status' => MembershipStatus::Removed]);

    $this->actingAs($user->refresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('shows the team switcher in sidebar and mobile header only when the user has more than one active team', function () {
    $teamA = Team::factory()->create(['name' => 'Team Alpha']);
    $teamB = Team::factory()->create(['name' => 'Team Beta']);
    $otherTeam = Team::factory()->create(['name' => 'Secret Team']);
    $removedTeam = Team::factory()->create(['name' => 'Removed Team']);

    $multiTeamUser = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($multiTeamUser)->for($teamA)->create(['created_at' => now()->subDays(2)]);
    Membership::factory()->for($multiTeamUser)->for($teamB)->create(['created_at' => now()->subDay()]);
    Membership::factory()->for($multiTeamUser)->for($removedTeam)->removed()->create();

    $this->actingAs($multiTeamUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-test="sidebar-team-switcher"')
        ->assertSeeHtml('data-test="mobile-team-switcher"')
        ->assertDontSeeHtml('data-test="sidebar-team-name"')
        ->assertDontSeeHtml('data-test="mobile-team-name"')
        ->assertSee('Team Alpha')
        ->assertSee('Team Beta')
        ->assertDontSee('Secret Team')
        ->assertDontSee('Removed Team');

    $singleTeamUser = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($singleTeamUser)->for($teamA)->create();
    Membership::factory()->for($singleTeamUser)->for($removedTeam)->removed()->create();

    $this->actingAs($singleTeamUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-test="sidebar-team-name"')
        ->assertSeeHtml('data-test="mobile-team-name"')
        ->assertDontSeeHtml('data-test="sidebar-team-switcher"')
        ->assertDontSeeHtml('data-test="mobile-team-switcher"')
        ->assertSee('Team Alpha')
        ->assertDontSee('Team Beta')
        ->assertDontSee('Secret Team')
        ->assertDontSee('Removed Team');
});

it('renders team switcher strings in Danish for Danish users', function () {
    $user = User::factory()->create(['locale' => 'da']);
    $teamA = Team::factory()->create(['name' => 'Hold A']);
    $teamB = Team::factory()->create(['name' => 'Hold B']);

    Membership::factory()->for($user)->for($teamA)->create(['created_at' => now()->subDays(2)]);
    Membership::factory()->for($user)->for($teamB)->create(['created_at' => now()->subDay()]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Hold')
        ->assertSee('Skift hold');
});
