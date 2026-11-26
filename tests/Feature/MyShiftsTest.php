<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

it('lists upcoming and past shifts with status and planned hours for the current member', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();

    $otherVolunteer = User::factory()->create(['locale' => 'en']);
    $otherMembership = Membership::factory()->for($otherVolunteer)->for($team)->create();

    $pastSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
    ]);
    $pastShift = Shift::factory()->for($pastSlot)->for($membership)->confirmed()->create();

    $upcomingSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 20:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-07 08:00', 'Europe/Copenhagen')->utc(),
    ]);
    $upcomingShift = Shift::factory()->for($upcomingSlot)->for($membership)->confirmed()->create();

    $otherSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-08 09:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-08 13:00', 'Europe/Copenhagen')->utc(),
    ]);
    $otherShift = Shift::factory()->for($otherSlot)->for($otherMembership)->confirmed()->create();

    $this->actingAs($volunteer);

    $this->get(route('my-shifts.index'))
        ->assertOk()
        ->assertSee('Upcoming shifts')
        ->assertSee('Past shifts')
        ->assertSee('data-test="upcoming-shift-'.$upcomingShift->id.'"', false)
        ->assertSee('20:00–08:00')
        ->assertSee('12 h')
        ->assertSee('Confirmed')
        ->assertSee('data-test="past-shift-'.$pastShift->id.'"', false)
        ->assertSee('08:00–16:00')
        ->assertSee('8 h')
        ->assertDontSee('data-test="upcoming-shift-'.$otherShift->id.'"', false)
        ->assertDontSee('09:00–13:00');
});

it('follows the current team on my shifts across two teams', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-01 12:00:00', 'Europe/Copenhagen'));

    $teamA = Team::factory()->create(['name' => 'Team A', 'timezone' => 'Europe/Copenhagen']);
    $seasonA = Season::factory()->for($teamA)->create();
    $teamA->update(['current_season_id' => $seasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team B', 'timezone' => 'Europe/Copenhagen']);
    $seasonB = Season::factory()->for($teamB)->create();
    $teamB->update(['current_season_id' => $seasonB->id]);

    $user = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $membershipA = Membership::factory()->for($user)->for($teamA)->create();
    $membershipB = Membership::factory()->for($user)->for($teamB)->create();

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    $shiftA = Shift::factory()->for($slotA)->for($membershipA)->confirmed()->create();

    $slotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 14:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 19:00', 'Europe/Copenhagen')->utc(),
    ]);
    $shiftB = Shift::factory()->for($slotB)->for($membershipB)->confirmed()->create();

    $this->actingAs($user);

    $this->get(route('my-shifts.index'))
        ->assertOk()
        ->assertSee('data-test="upcoming-shift-'.$shiftA->id.'"', false)
        ->assertSee('4 h')
        ->assertDontSee('data-test="upcoming-shift-'.$shiftB->id.'"', false);

    $this->post(route('current-team.update'), ['team_id' => $teamB->id])
        ->assertRedirect();

    $this->get(route('my-shifts.index'))
        ->assertOk()
        ->assertSee('data-test="upcoming-shift-'.$shiftB->id.'"', false)
        ->assertSee('5 h')
        ->assertDontSee('data-test="upcoming-shift-'.$shiftA->id.'"', false);
});
