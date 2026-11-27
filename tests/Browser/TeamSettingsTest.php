<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;

it('lets an admin change team settings and see them saved', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Freja Hansen', 'locale' => 'en']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => '2027-08-04 06:00:00',
        'ends_at' => '2027-08-04 14:00:00',
        'capacity' => 4,
    ]);
    Shift::factory()->confirmed()->for($slot)->for($volunteerMembership)->create();

    $this->actingAs($admin);

    visit('/schedule')
        ->assertSee('Freja')
        ->click('Team settings')
        ->assertPathIs('/team/settings')
        ->assertSee('Team settings')
        ->assertValue('cancellationDeadlineHours', '48')
        ->fill('cancellationDeadlineHours', '24')
        ->click('@shifts-require-confirmation')
        ->click('@show-confirmed-volunteer-names')
        ->click('@save-team-settings')
        ->assertSee('Team settings saved.')
        ->navigate('/team/settings')
        ->assertValue('cancellationDeadlineHours', '24')
        ->navigate('/schedule')
        ->assertPathIs('/schedule')
        ->assertDontSee('Freja')
        ->assertNoSmoke();

    $team->refresh();

    expect($team->settings->cancellationDeadlineHours)->toBe(24)
        ->and($team->settings->shiftsRequireConfirmation)->toBeTrue()
        ->and($team->settings->showConfirmedVolunteerNames)->toBeFalse();
});
