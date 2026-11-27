<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;

it('lets an admin filter members and inspect shift history in hours overview', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $bob = Membership::factory()->for(User::factory()->create(['name' => 'Bob Volunteer']))->for($team)->create();
    $clara = Membership::factory()->removed()->for(User::factory()->create(['name' => 'Clara Former']))->for($team)->create();

    $yesterday = now()->toImmutable()->setTimezone('Europe/Copenhagen')->subDay()->startOfDay();

    $slot1 = Slot::factory()->for($season)->create([
        'starts_at' => $yesterday->setTime(12, 0)->utc(),
        'ends_at' => $yesterday->setTime(16, 0)->utc(),
    ]);
    $slot2 = Slot::factory()->for($season)->create([
        'starts_at' => $yesterday->setTime(18, 0)->utc(),
        'ends_at' => $yesterday->setTime(20, 0)->utc(),
    ]);

    Shift::factory()->for($slot1)->for($bob)->adjusted(
        startsAt: $yesterday->setTime(12, 30),
        endsAt: $yesterday->setTime(15, 45),
        recordedBy: $admin,
        recordedAt: $yesterday->setTime(16, 10),
    )->create();

    Shift::factory()->for($slot2)->for($clara)->noShow(
        recordedBy: $admin,
        recordedAt: $yesterday->setTime(20, 15),
    )->create();

    $this->actingAs($admin);

    visit('/dashboard')
        ->click('Hours overview')
        ->assertPathIs('/hours')
        ->assertSee('Hours overview')
        ->assertSee('Bob Volunteer')
        ->assertSee('3 h 15 m')
        ->assertSee('Clara Former')
        ->assertSee('Removed')
        ->click("@toggle-member-{$bob->id}")
        ->assertSee('12:00–16:00')
        ->assertSee('12:30–15:45')
        ->assertSee('Edited by Alice Admin')
        ->select('@hours-status-filter', 'removed')
        ->assertSee('Clara Former')
        ->assertDontSee('Bob Volunteer')
        ->click("@toggle-member-{$clara->id}")
        ->assertSee('No-show')
        ->assertSee('0 h')
        ->assertNoSmoke();
});

it('lets an admin find members short of their season goal while filtering hours by date', function () {
    $team = Team::factory()->create([
        'timezone' => 'Europe/Copenhagen',
        'settings' => new TeamSettings(defaultHourGoal: 4),
    ]);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();
    $short = Membership::factory()->for(User::factory()->create(['name' => 'Bob Short']))->for($team)->create();
    $met = Membership::factory()->for(User::factory()->create(['name' => 'Clara Met']))->for($team)->create();

    $day = now()->toImmutable()->setTimezone($team->timezone)->subDay();
    $shortSlot = Slot::factory()->for($season)->create([
        'starts_at' => $day->setTime(10, 0)->utc(),
        'ends_at' => $day->setTime(12, 0)->utc(),
    ]);
    $metSlot = Slot::factory()->for($season)->create([
        'starts_at' => $day->setTime(10, 0)->utc(),
        'ends_at' => $day->setTime(14, 0)->utc(),
    ]);
    Shift::factory()->for($shortSlot)->for($short)->confirmed()->create();
    Shift::factory()->for($metSlot)->for($met)->pending()->create();

    $this->actingAs($admin);

    visit('/dashboard')
        ->click('Hours overview')
        ->assertPathIs('/hours')
        ->assertSeeIn("@goal-progress-{$short->id}", '2 / 4 h')
        ->assertSeeIn("@short-of-goal-{$short->id}", 'Short of goal')
        ->assertSeeIn("@goal-progress-{$met->id}", '4 / 4 h')
        ->fill('@hours-from-date', $day->addDay()->toDateString())
        ->assertSee('Goal progress and the Short of goal filter cover the full season.')
        ->assertSeeIn("@planned-hours-{$short->id}", '0 h')
        ->assertSeeIn("@goal-progress-{$short->id}", '2 / 4 h')
        ->check('@hours-short-of-goal-filter')
        ->assertSee('Bob Short')
        ->assertDontSee('Clara Met')
        ->click('@hours-clear-filters')
        ->assertSee('Clara Met')
        ->assertSeeIn("@planned-hours-{$short->id}", '2 h')
        ->assertNotChecked('@hours-short-of-goal-filter')
        ->assertNoSmoke();
});
