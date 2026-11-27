<?php

use App\Auth\CurrentTeam;
use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('casts team settings to TeamSettings with defaults on a new team', function () {
    $team = Team::factory()->create();

    expect($team->settings)
        ->toBeInstanceOf(TeamSettings::class)
        ->and($team->settings->cancellationDeadlineHours)->toBe(48)
        ->and($team->settings->shiftsRequireConfirmation)->toBeFalse()
        ->and($team->settings->showConfirmedVolunteerNames)->toBeTrue();

    $reloaded = $team->fresh();

    expect($reloaded->settings)
        ->toBeInstanceOf(TeamSettings::class)
        ->and($reloaded->settings->cancellationDeadlineHours)->toBe(48)
        ->and($reloaded->settings->shiftsRequireConfirmation)->toBeFalse()
        ->and($reloaded->settings->showConfirmedVolunteerNames)->toBeTrue();
});

it('persists TeamSettings to the jsonb column and falls back to defaults for missing keys', function () {
    $team = Team::factory()->create();

    $team->update([
        'settings' => new TeamSettings(
            cancellationDeadlineHours: 0,
            shiftsRequireConfirmation: true,
            showConfirmedVolunteerNames: false,
        ),
    ]);

    $reloaded = $team->fresh();

    expect($reloaded->settings->cancellationDeadlineHours)->toBe(0)
        ->and($reloaded->settings->shiftsRequireConfirmation)->toBeTrue()
        ->and($reloaded->settings->showConfirmedVolunteerNames)->toBeFalse();

    DB::table('teams')
        ->where('id', $team->id)
        ->update(['settings' => json_encode(['cancellation_deadline_hours' => 12])]);

    $partial = $team->fresh();

    expect($partial->settings->cancellationDeadlineHours)->toBe(12)
        ->and($partial->settings->shiftsRequireConfirmation)->toBeFalse()
        ->and($partial->settings->showConfirmedVolunteerNames)->toBeTrue();
});

it('only lets admins of the current team open and save team settings across two teams', function () {
    $teamA = Team::factory()->create(['name' => 'Team Alpha']);
    $teamB = Team::factory()->create(['name' => 'Team Beta']);

    $user = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($user)->for($teamA)->create();
    Membership::factory()->for($user)->for($teamB)->create();

    $this->actingAs($user)
        ->get(route('team.settings'))
        ->assertOk()
        ->assertSee('Team settings')
        ->assertSee('Team Alpha');

    Livewire::actingAs($user)
        ->test('pages::team.settings')
        ->assertSet('cancellationDeadlineHours', 48)
        ->assertSet('shiftsRequireConfirmation', false)
        ->assertSet('showConfirmedVolunteerNames', true)
        ->set('cancellationDeadlineHours', 24)
        ->set('shiftsRequireConfirmation', true)
        ->set('showConfirmedVolunteerNames', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Team settings saved.');

    $teamA->refresh();
    $teamB->refresh();

    expect($teamA->settings->cancellationDeadlineHours)->toBe(24)
        ->and($teamA->settings->shiftsRequireConfirmation)->toBeTrue()
        ->and($teamA->settings->showConfirmedVolunteerNames)->toBeFalse()
        ->and($teamB->settings->cancellationDeadlineHours)->toBe(48)
        ->and($teamB->settings->shiftsRequireConfirmation)->toBeFalse()
        ->and($teamB->settings->showConfirmedVolunteerNames)->toBeTrue();

    app(CurrentTeam::class)->switchTo($user, $teamB);

    $this->actingAs($user)
        ->get(route('team.settings'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::team.settings')
        ->assertForbidden();
});

it('shows the team settings link in the sidebar to admins only', function () {
    $team = Team::factory()->create();

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteer)->for($team)->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('team.settings'))
        ->assertSee('Team settings');

    $this->actingAs($volunteer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('team.settings'));
});

it('accepts zero hours and rejects negative, fractional, or non-numeric cancellation deadlines', function (mixed $invalidDeadline) {
    $team = Team::factory()->create();
    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    Livewire::actingAs($admin)
        ->test('pages::team.settings')
        ->set('cancellationDeadlineHours', $invalidDeadline)
        ->call('save')
        ->assertHasErrors(['cancellationDeadlineHours']);

    expect($team->fresh()->settings->cancellationDeadlineHours)->toBe(48);
})->with([
    'negative' => -1,
    'fractional' => '1.5',
    'non-numeric' => 'abc',
    'empty' => '',
]);

it('accepts zero hours as a valid cancellation deadline', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    Livewire::actingAs($admin)
        ->test('pages::team.settings')
        ->set('cancellationDeadlineHours', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->settings->cancellationDeadlineHours)->toBe(0);
});

it('shows only first names of confirmed volunteers on each slot when the names setting is on', function () {
    $team = Team::factory()->create([
        'name' => 'Bar Team',
        'timezone' => 'Europe/Copenhagen',
    ]);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $viewer = User::factory()->create([
        'name' => 'Viewer Person',
        'email' => 'viewer@example.com',
        'locale' => 'en',
    ]);
    Membership::factory()->for($viewer)->for($team)->create();

    $confirmedOne = User::factory()->create(['name' => 'Freja Hansen']);
    $confirmedOneMembership = Membership::factory()->for($confirmedOne)->for($team)->create();

    $confirmedTwo = User::factory()->create(['name' => 'Mikkel Larsen']);
    $confirmedTwoMembership = Membership::factory()->for($confirmedTwo)->for($team)->create();

    $pendingVolunteer = User::factory()->create(['name' => 'Sofie Pending']);
    $pendingMembership = Membership::factory()->for($pendingVolunteer)->for($team)->create();

    $cancelledVolunteer = User::factory()->create(['name' => 'Kasper Cancelled']);
    $cancelledMembership = Membership::factory()->for($cancelledVolunteer)->for($team)->create();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => '2027-08-04 06:00:00',
        'ends_at' => '2027-08-04 14:00:00',
        'capacity' => 4,
    ]);

    Shift::factory()->confirmed()->for($slot)->for($confirmedOneMembership)->create();
    Shift::factory()->confirmed()->for($slot)->for($confirmedTwoMembership)->create();
    Shift::factory()->for($slot)->for($pendingMembership)->create(['status' => ShiftStatus::Pending]);
    Shift::factory()->for($slot)->for($cancelledMembership)->create(['status' => ShiftStatus::Cancelled]);

    $this->actingAs($viewer)
        ->get(route('schedule.index'))
        ->assertOk()
        ->assertSee('Freja')
        ->assertSee('Mikkel')
        ->assertDontSee('Hansen')
        ->assertDontSee('Larsen')
        ->assertDontSee('Sofie')
        ->assertDontSee('Kasper');
});

it('shows no volunteer names on the schedule when the names setting is off', function () {
    $team = Team::factory()->create([
        'name' => 'Bar Team',
        'timezone' => 'Europe/Copenhagen',
        'settings' => new TeamSettings(showConfirmedVolunteerNames: false),
    ]);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $viewer = User::factory()->create([
        'name' => 'Viewer Person',
        'email' => 'viewer@example.com',
        'locale' => 'en',
    ]);
    Membership::factory()->for($viewer)->for($team)->create();

    $confirmedVolunteer = User::factory()->create(['name' => 'Freja Hansen']);
    $confirmedMembership = Membership::factory()->for($confirmedVolunteer)->for($team)->create();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => '2027-08-04 06:00:00',
        'ends_at' => '2027-08-04 14:00:00',
        'capacity' => 4,
    ]);

    Shift::factory()->confirmed()->for($slot)->for($confirmedMembership)->create();

    $this->actingAs($viewer)
        ->get(route('schedule.index'))
        ->assertOk()
        ->assertDontSee('Freja')
        ->assertDontSee('Hansen');
});

it('sets and clears the default hour goal through team settings', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $settings = Livewire::actingAs($admin)
        ->test('pages::team.settings')
        ->assertSee('Default hour goal')
        ->assertSet('defaultHourGoal', '')
        ->set('defaultHourGoal', '24')
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->settings->defaultHourGoal)->toBe(24);

    $settings
        ->set('defaultHourGoal', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->settings->defaultHourGoal)->toBeNull();
});

it('rejects invalid default hour goals', function (string $hours) {
    $team = Team::factory()->create(['settings' => ['default_hour_goal' => 24]]);
    $admin = Membership::factory()->admin()->for($team)->create()->user()->firstOrFail();

    Livewire::actingAs($admin)
        ->test('pages::team.settings')
        ->set('defaultHourGoal', $hours)
        ->call('save')
        ->assertHasErrors(['defaultHourGoal']);

    expect($team->fresh()->settings->defaultHourGoal)->toBe(24);
})->with([
    'zero' => '0',
    'negative' => '-2',
    'fractional' => '2.5',
    'non-numeric' => 'abc',
]);
