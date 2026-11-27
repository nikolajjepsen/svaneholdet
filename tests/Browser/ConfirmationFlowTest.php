<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Carbon\CarbonImmutable;

it('lets a volunteer request a shift and an admin confirm it', function () {
    $team = Team::factory()->create([
        'name' => 'Bar Team',
        'timezone' => 'Europe/Copenhagen',
        'settings' => new TeamSettings(shiftsRequireConfirmation: true, defaultHourGoal: 24),
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();

    $this->actingAs($volunteer);

    visit('/schedule')
        ->on()->mobile()
        ->assertSeeIn('@hour-goal-progress', '0 / 24 h · 0 confirmed, 0 pending')
        ->assertSee('08:00–16:00')
        ->assertSee('0 / 2')
        ->click("@sign-up-slot-{$slot->id}")
        ->assertSee('Your shift request has been sent.')
        ->assertSeeIn('@hour-goal-progress', '8 / 24 h · 0 confirmed, 1 pending')
        ->assertSee('0 / 2')
        ->assertSee('Pending')
        ->navigate('/my-shifts')
        ->assertPathIs('/my-shifts')
        ->assertSeeIn('@hour-goal-progress', '8 / 24 h · 0 confirmed, 1 pending')
        ->assertSeeIn('@my-planned-hours', '0 h')
        ->assertSee('Pending')
        ->assertNoSmoke();

    $shift = Shift::query()
        ->where('slot_id', $slot->id)
        ->where('membership_id', $volunteerMembership->id)
        ->sole();

    expect($shift->status)->toBe(ShiftStatus::Pending);

    $this->actingAs($admin);

    visit('/schedule')
        ->assertSee('1 pending')
        ->click("@open-slot-{$slot->id}")
        ->assertPathIs("/schedule/slots/{$slot->id}")
        ->assertSee('Bob Volunteer')
        ->assertSee('0 / 2')
        ->click("@confirm-shift-{$shift->id}")
        ->assertSee('Shift confirmed.')
        ->assertSee('1 / 2')
        ->assertSee('No pending shifts.')
        ->assertNoSmoke();

    expect($shift->fresh()->status)->toBe(ShiftStatus::Confirmed);
    expect($shift->fresh()->confirmed_at)->not->toBeNull();

    $this->actingAs($volunteer);

    visit('/my-shifts')
        ->on()->mobile()
        ->assertSeeIn('@hour-goal-progress', '8 / 24 h · 1 confirmed, 0 pending')
        ->assertSeeIn('@my-planned-hours', '8 h')
        ->assertNoSmoke();
});
