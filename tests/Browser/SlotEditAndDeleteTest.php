<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

it('lets an admin edit a slot series and delete a slot', function () {
    $team = Team::factory()->create([
        'name' => 'Bar Team',
        'timezone' => 'Europe/Copenhagen',
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $series = SlotSeries::factory()->for($season)->create([
        'local_start_time' => '08:00',
        'local_end_time' => '16:00',
        'capacity' => 4,
    ]);

    $slotOne = Slot::factory()->for($season)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $slotTwo = Slot::factory()->for($season)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();
    Shift::factory()->confirmed()->for($slotOne)->for($volunteerMembership)->create();

    $this->actingAs($admin);

    visit('/schedule')
        ->assertSee('08:00–16:00')
        ->click("@open-series-{$series->id}")
        ->assertPathIs("/schedule/series/{$series->id}")
        ->assertSee('Slot series')
        ->fill('startTime', '09:00')
        ->click('@save-series-submit')
        ->assertSee('Slot series updated.')
        ->assertSee('09:00–16:00')
        ->click("@open-series-slot-{$slotOne->id}")
        ->assertPathIs("/schedule/slots/{$slotOne->id}")
        ->assertSee('09:00–16:00')
        ->click('@open-delete-slot')
        ->assertSee('1 confirmed and 0 pending')
        ->click('@confirm-delete-slot')
        ->assertPathIs('/schedule')
        ->assertSee('Slot deleted.')
        ->assertNoSmoke();

    expect(Slot::query()->find($slotOne->id))->toBeNull();
    expect($slotTwo->fresh()->starts_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('09:00');
});
