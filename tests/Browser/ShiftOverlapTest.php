<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

it('lets a volunteer accept a same-team overlap warning in a Flux modal', function () {
    $team = Team::factory()->create([
        'name' => 'Bar Team',
        'timezone' => 'Europe/Copenhagen',
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $otherTeam = Team::factory()->create([
        'name' => 'Stage Team',
        'timezone' => 'Europe/Copenhagen',
    ]);
    $otherSeason = Season::factory()->for($otherTeam)->create(['name' => Season::DEFAULT_NAME]);
    $otherTeam->update(['current_season_id' => $otherSeason->id]);

    $existingSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $overlappingSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $volunteer = User::factory()->create([
        'name' => 'Bob Volunteer',
        'locale' => 'en',
        'last_chosen_team_id' => $team->id,
    ]);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    Membership::factory()->for($volunteer)->for($otherTeam)->create();

    Shift::factory()->confirmed()->for($existingSlot)->for($membership)->create();

    $this->actingAs($volunteer);

    visit('/schedule')
        ->assertSee('08:00–14:00')
        ->assertSee('12:00–18:00')
        ->click("@sign-up-slot-{$overlappingSlot->id}")
        ->assertSee('Before you sign up')
        ->assertSee('08:00–14:00')
        ->click('@confirm-sign-up-warning')
        ->assertSee('You are signed up for this slot.')
        ->assertSee('1 / 3')
        ->assertNoSmoke();

    $shift = Shift::query()
        ->where('slot_id', $overlappingSlot->id)
        ->where('membership_id', $membership->id)
        ->sole();

    expect($shift->status)->toBe(ShiftStatus::Confirmed);
    expect($shift->confirmed_at)->not->toBeNull();
});
