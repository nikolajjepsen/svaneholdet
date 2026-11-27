<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

it('lets an admin roll out slots with preview and duplicate warning and save', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $today = now()->setTimezone($team->timezone);
    $firstDate = $today->setDay(10)->format('Y-m-d');
    $secondDate = $today->setDay(11)->format('Y-m-d');

    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse($firstDate.' 08:00', $team->timezone)->utc(),
        'ends_at' => CarbonImmutable::parse($firstDate.' 16:00', $team->timezone)->utc(),
        'capacity' => 4,
    ]);

    $this->actingAs($admin);

    visit('/schedule')
        ->click('@open-rollout')
        ->assertPathIs('/schedule/rollout')
        ->assertSee('Roll out slots')
        ->click("@calendar-day-{$firstDate}")
        ->click("@calendar-day-{$secondDate}")
        ->click('@add-interval')
        ->fill('@interval-1-start', '20:00')
        ->fill('@interval-1-end', '08:00')
        ->fill('@interval-1-capacity', '3')
        ->assertSee('4 slots on 2 days, 1 already exists')
        ->assertVisible('@rollout-duplicate-warning')
        ->assertSee('Ends next day')
        ->click('@rollout-save')
        ->assertPathIs('/schedule')
        ->assertSee('4 slots created.')
        ->assertSee('08:00–16:00')
        ->assertSee('20:00–08:00')
        ->assertSee('Ends next day')
        ->assertNoSmoke();

    expect(SlotSeries::query()->where('season_id', $season->id)->count())->toBe(2)
        ->and(Slot::query()->where('season_id', $season->id)->count())->toBe(5);
});
