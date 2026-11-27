<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;

it('lets an admin inspect live staffing counts, navigate days across midnight, and open a slot from day overview', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $bob = Membership::factory()->for(User::factory()->create(['name' => 'Bob Volunteer']))->for($team)->create();
    $clara = Membership::factory()->for(User::factory()->create(['name' => 'Clara Volunteer']))->for($team)->create();

    $todayLocal = now()->toImmutable()->setTimezone('Europe/Copenhagen')->startOfDay();

    // Today 16:00–00:00 (ends at exact midnight -> only on today)
    $midnightSlot = Slot::factory()->for($season)->create([
        'starts_at' => $todayLocal->setTime(16, 0)->utc(),
        'ends_at' => $todayLocal->addDay()->setTime(0, 0)->utc(),
        'capacity' => 2,
    ]);

    // Today 22:00–02:00 (spans past midnight -> on today AND tomorrow)
    $overnightSlot = Slot::factory()->for($season)->create([
        'starts_at' => $todayLocal->setTime(22, 0)->utc(),
        'ends_at' => $todayLocal->addDay()->setTime(2, 0)->utc(),
        'capacity' => 3,
    ]);

    // Bob is confirmed on both slots -> 1 unique confirmed member today, 1 on each slot.
    Shift::factory()->for($midnightSlot)->for($bob)->confirmed()->create();
    Shift::factory()->for($overnightSlot)->for($bob)->confirmed()->create();

    // Clara is pending on the midnight slot.
    Shift::factory()->for($midnightSlot)->for($clara)->pending()->create();

    $this->actingAs($admin);

    visit('/dashboard')
        ->click('Day overview')
        ->assertPathIs('/day-overview')
        ->assertSee('Day overview')
        ->assertSee('Unique confirmed members')
        ->assertSee('16:00–00:00')
        ->assertSee('22:00–02:00')
        ->assertSee('1 / 2')
        ->assertSee('1 / 3')
        ->assertSee('1 pending')
        ->click('@next-day')
        ->assertDontSee('16:00–00:00')
        ->assertSee('22:00–02:00')
        ->assertSee('Started previous day')
        ->click("@open-slot-{$overnightSlot->id}")
        ->assertPathIs("/schedule/slots/{$overnightSlot->id}")
        ->assertSee('Bob Volunteer')
        ->assertNoSmoke();
});
