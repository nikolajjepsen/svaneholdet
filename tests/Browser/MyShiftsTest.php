<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;

it('shows planned and worked hours totals with adjusted times and no-show status on my shifts', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();

    $yesterday = now()->toImmutable()->setTimezone('Europe/Copenhagen')->subDay()->startOfDay();
    $tomorrow = now()->toImmutable()->setTimezone('Europe/Copenhagen')->addDay()->startOfDay();

    // Ended unedited confirmed shift: 4 h planned, 4 h worked
    $plannedSlot = Slot::factory()->for($season)->create([
        'starts_at' => $yesterday->setTime(8, 0)->utc(),
        'ends_at' => $yesterday->setTime(12, 0)->utc(),
    ]);
    Shift::factory()->for($plannedSlot)->for($membership)->confirmed()->create();

    // Ended adjusted shift: 4 h planned, 3 h 15 m worked
    $adjustedSlot = Slot::factory()->for($season)->create([
        'starts_at' => $yesterday->setTime(13, 0)->utc(),
        'ends_at' => $yesterday->setTime(17, 0)->utc(),
    ]);
    Shift::factory()->for($adjustedSlot)->for($membership)->adjusted(
        startsAt: $yesterday->setTime(13, 30),
        endsAt: $yesterday->setTime(16, 45),
        recordedBy: $admin,
        recordedAt: $yesterday->setTime(17, 10),
    )->create();

    // Ended no-show shift: 2 h planned, 0 h worked
    $noShowSlot = Slot::factory()->for($season)->create([
        'starts_at' => $yesterday->setTime(18, 0)->utc(),
        'ends_at' => $yesterday->setTime(20, 0)->utc(),
    ]);
    Shift::factory()->for($noShowSlot)->for($membership)->noShow(
        recordedBy: $admin,
        recordedAt: $yesterday->setTime(20, 15),
    )->create();

    // Upcoming confirmed shift: 4 h planned, 0 h worked
    $upcomingSlot = Slot::factory()->for($season)->create([
        'starts_at' => $tomorrow->setTime(10, 0)->utc(),
        'ends_at' => $tomorrow->setTime(14, 0)->utc(),
    ]);
    Shift::factory()->for($upcomingSlot)->for($membership)->confirmed()->create();

    $this->actingAs($volunteer);

    visit('/dashboard')
        ->click('My shifts')
        ->assertPathIs('/my-shifts')
        ->assertSee('Planned hours')
        ->assertSee('14 h')
        ->assertSee('Worked hours')
        ->assertSee('7 h 15 m')
        ->assertSee('13:30–16:45')
        ->assertSee('3 h 15 m')
        ->assertSee('Adjusted')
        ->assertSee('No-show')
        ->assertDontSee('Alice Admin')
        ->assertDontSee('Edited by')
        ->assertNoSmoke();
});
