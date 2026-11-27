<?php

use App\Auth\CurrentTeam;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

it('forbids volunteers from opening any team administration page', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $season = Season::factory()->for($team)->create();
    $series = SlotSeries::factory()->for($season)->create();
    $volunteer = User::factory()->create(['last_chosen_team_id' => $team->id]);
    Membership::factory()->for($volunteer)->for($team)->create();
    Membership::factory()->admin()->for($volunteer)->for($otherTeam)->create();

    $this->actingAs($volunteer);

    foreach (['team.settings', 'members.index', 'invitations.create', 'day-overview.index', 'hours.index', 'schedule.rollout'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
    $this->get(route('schedule.series.show', $series))->assertForbidden();

    foreach (['dashboard', 'profile.edit', 'language.edit', 'appearance.edit'] as $route) {
        $this->get(route($route))
            ->assertOk()
            ->assertDontSee(route('team.settings'))
            ->assertDontSee(route('members.index'));
    }
});

it('rejects hour goal Livewire writes when the current team changes after mounting', function (MembershipStatus $originalTeamStatus) {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['last_chosen_team_id' => $team->id]);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($team)->create();
    Membership::factory()->admin()->for($admin)->for($otherTeam)->create();

    $settingsPage = Livewire::actingAs($admin)->test('pages::team.settings');
    $membersPage = Livewire::test('pages::members.index')->call('editHourGoal', $adminMembership->id);

    app(CurrentTeam::class)->switchTo($admin, $otherTeam);
    $adminMembership->update(['status' => $originalTeamStatus]);

    $settingsPage->update(updates: ['defaultHourGoal' => '18'], calls: [['method' => 'save', 'params' => []]])
        ->assertForbidden();
    $membersPage->update(updates: ['hourGoal' => '12'], calls: [['method' => 'saveHourGoal', 'params' => []]])
        ->assertForbidden();

    expect($team->fresh()->settings->defaultHourGoal)->toBeNull();
    expect($otherTeam->fresh()->settings->defaultHourGoal)->toBeNull();
    $this->assertDatabaseEmpty('season_hour_goals');
})->with([
    'admin of both teams' => MembershipStatus::Active,
    'admin no longer belongs to the original team' => MembershipStatus::Removed,
]);

it('rejects hour goal Livewire writes when the admin loses permission while the pages are open', function () {
    $team = Team::factory()->create();
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);
    $admin = User::factory()->create();
    $adminMembership = Membership::factory()->admin()->for($admin)->for($team)->create();
    Membership::factory()->admin()->for($team)->create();

    $settingsPage = Livewire::actingAs($admin)->test('pages::team.settings');
    $membersPage = Livewire::test('pages::members.index')->call('editHourGoal', $adminMembership->id);
    $adminMembership->update(['role' => MembershipRole::Volunteer]);

    $settingsPage->update(updates: ['defaultHourGoal' => '18'], calls: [['method' => 'save', 'params' => []]])
        ->assertForbidden();
    $membersPage->update(updates: ['hourGoal' => '12'], calls: [['method' => 'saveHourGoal', 'params' => []]])
        ->assertForbidden();

    expect($team->fresh()->settings->defaultHourGoal)->toBeNull();
    $this->assertDatabaseEmpty('season_hour_goals');
});
