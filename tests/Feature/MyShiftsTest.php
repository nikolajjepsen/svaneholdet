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

it('shows only shifts from the team current season on my shifts', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-01 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $oldSeason = Season::factory()->for($team)->create(['name' => 'Smukfest 2026']);
    $currentSeason = Season::factory()->for($team)->create(['name' => 'Smukfest 2027']);
    $team->update(['current_season_id' => $currentSeason->id]);

    $volunteer = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $team->id]);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();

    $oldSlot = Slot::factory()->for($oldSeason)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-03 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-03 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    $oldShift = Shift::factory()->for($oldSlot)->for($membership)->confirmed()->create();

    $currentSlot = Slot::factory()->for($currentSeason)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 20:00', 'Europe/Copenhagen')->utc(),
    ]);
    $currentShift = Shift::factory()->for($currentSlot)->for($membership)->confirmed()->create();

    $this->actingAs($volunteer);

    $this->get(route('my-shifts.index'))
        ->assertOk()
        ->assertSee('data-test="upcoming-shift-'.$currentShift->id.'"', false)
        ->assertSee('14:00–20:00')
        ->assertDontSee('data-test="upcoming-shift-'.$oldShift->id.'"', false)
        ->assertDontSee('08:00–12:00');
});

it('displays current-season planned and worked hours totals with overlap deduplication and attendance rules', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 15:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Audit Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();

    // Two overlapping ended confirmed shifts on Aug 4: 08:00-12:00 and 10:00-14:00 -> union is 6 h planned, 6 h worked
    $overlapSlotA = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    $overlapSlotB = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($overlapSlotA)->for($membership)->confirmed()->create();
    Shift::factory()->for($overlapSlotB)->for($membership)->confirmed()->create();

    // Ended adjusted (shortened) shift on Aug 5: planned 08:00-12:00 (4 h), actual 08:30-11:45 (3 h 15 m)
    $adjustedSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($adjustedSlot)->for($membership)->adjusted(
        startsAt: CarbonImmutable::parse('2027-08-05 08:30', 'Europe/Copenhagen'),
        endsAt: CarbonImmutable::parse('2027-08-05 11:45', 'Europe/Copenhagen'),
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 12:10', 'Europe/Copenhagen'),
    )->create();

    // Ended no-show shift on Aug 5: planned 12:30-14:30 (2 h), worked 0 h
    $noShowSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:30', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 14:30', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($noShowSlot)->for($membership)->noShow(
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 14:40', 'Europe/Copenhagen'),
    )->create();

    // Future unedited confirmed shift on Aug 6: planned 10:00-15:00 (5 h), worked 0 h (not ended yet)
    $futureSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 15:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($futureSlot)->for($membership)->confirmed()->create();

    // Pending and cancelled shifts must not count toward Planned or Worked hours
    $pendingSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-07 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-07 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($pendingSlot)->for($membership)->pending()->create();

    $cancelledSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-03 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-03 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($cancelledSlot)->for($membership)->cancelled()->create();

    // Total planned: 6 h + 4 h + 2 h + 5 h = 17 h
    // Total worked: 6 h + 3 h 15 m + 0 h + 0 h = 9 h 15 m
    $this->actingAs($volunteer);

    $response = $this->get(route('my-shifts.index'));
    $response->assertOk()
        ->assertSee('Planned hours')
        ->assertSee('Worked hours')
        ->assertSeeInOrder(['data-test="my-planned-hours"', '17 h'], false)
        ->assertSeeInOrder(['data-test="my-worked-hours"', '9 h 15 m'], false);
});

it('shows adjusted actual times and no-show indicators on confirmed shift cards without exposing admin audit metadata', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 15:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Secret Audit Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();

    // Past adjusted shift
    $pastAdjustedSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    $pastAdjustedShift = Shift::factory()->for($pastAdjustedSlot)->for($membership)->adjusted(
        startsAt: CarbonImmutable::parse('2027-08-05 08:30', 'Europe/Copenhagen'),
        endsAt: CarbonImmutable::parse('2027-08-05 11:45', 'Europe/Copenhagen'),
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 12:17', 'Europe/Copenhagen'),
    )->create();

    // Past no-show shift
    $pastNoShowSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
    ]);
    $pastNoShowShift = Shift::factory()->for($pastNoShowSlot)->for($membership)->noShow(
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-04 14:23', 'Europe/Copenhagen'),
    )->create();

    // Upcoming/in-progress adjusted overnight shift (counts in Worked hours immediately)
    $upcomingAdjustedSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 22:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 02:00', 'Europe/Copenhagen')->utc(),
    ]);
    $upcomingAdjustedShift = Shift::factory()->for($upcomingAdjustedSlot)->for($membership)->adjusted(
        startsAt: CarbonImmutable::parse('2027-08-05 22:30', 'Europe/Copenhagen'),
        endsAt: CarbonImmutable::parse('2027-08-06 01:30', 'Europe/Copenhagen'),
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 14:55', 'Europe/Copenhagen'),
    )->create();

    // Upcoming no-show shift
    $upcomingNoShowSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 14:00', 'Europe/Copenhagen')->utc(),
    ]);
    $upcomingNoShowShift = Shift::factory()->for($upcomingNoShowSlot)->for($membership)->noShow(
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 14:58', 'Europe/Copenhagen'),
    )->create();

    $this->actingAs($volunteer);

    // Planned: 4 + 4 + 4 + 4 = 16 h; Worked: 3 h 15 m + 0 + 3 h + 0 = 6 h 15 m
    $this->get(route('my-shifts.index'))
        ->assertOk()
        ->assertSeeInOrder(['data-test="my-planned-hours"', '16 h'], false)
        ->assertSeeInOrder(['data-test="my-worked-hours"', '6 h 15 m'], false)
        ->assertSee('data-test="shift-actual-'.$pastAdjustedShift->id.'"', false)
        ->assertSee('08:30–11:45')
        ->assertSee('3 h 15 m')
        ->assertSee('data-test="shift-attendance-'.$pastAdjustedShift->id.'"', false)
        ->assertSee('Adjusted')
        ->assertSee('data-test="shift-attendance-'.$pastNoShowShift->id.'"', false)
        ->assertSee('No-show')
        ->assertSee('data-test="shift-actual-'.$upcomingAdjustedShift->id.'"', false)
        ->assertSee('22:30–01:30')
        ->assertSee('Ends next day')
        ->assertSee('data-test="shift-attendance-'.$upcomingNoShowShift->id.'"', false)
        ->assertDontSee('Secret Audit Admin')
        ->assertDontSee('Edited by')
        ->assertDontSee('Edited on')
        ->assertDontSee('12:17')
        ->assertDontSee('14:23')
        ->assertDontSee('14:55')
        ->assertDontSee('14:58');
});

it('hides admin audit metadata on my shifts even when an admin views their own shifts', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 15:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Self Admin', 'locale' => 'en']);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($team)->create();

    $otherAdmin = User::factory()->create(['name' => 'Other Auditor', 'locale' => 'en']);
    Membership::factory()->admin()->for($otherAdmin)->for($team)->create();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    $shift = Shift::factory()->for($slot)->for($adminMembership)->adjusted(
        startsAt: CarbonImmutable::parse('2027-08-05 09:00', 'Europe/Copenhagen'),
        endsAt: CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen'),
        recordedBy: $otherAdmin,
        recordedAt: CarbonImmutable::parse('2027-08-05 12:34', 'Europe/Copenhagen'),
    )->create();

    $this->actingAs($admin);

    $this->get(route('my-shifts.index'))
        ->assertOk()
        ->assertSee('data-test="shift-actual-'.$shift->id.'"', false)
        ->assertSee('09:00–12:00')
        ->assertDontSee('Other Auditor')
        ->assertDontSee('Edited by')
        ->assertDontSee('Edited on')
        ->assertDontSee('12:34');
});

it('scopes planned and worked hours totals to the current team and current season', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 15:00:00', 'Europe/Copenhagen'));

    $teamA = Team::factory()->create(['name' => 'Team A', 'timezone' => 'Europe/Copenhagen']);
    $oldSeasonA = Season::factory()->for($teamA)->create(['name' => 'Smukfest 2026']);
    $currentSeasonA = Season::factory()->for($teamA)->create(['name' => 'Smukfest 2027']);
    $teamA->update(['current_season_id' => $currentSeasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team B', 'timezone' => 'Europe/Copenhagen']);
    $currentSeasonB = Season::factory()->for($teamB)->create(['name' => 'Smukfest 2027']);
    $teamB->update(['current_season_id' => $currentSeasonB->id]);

    $user = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $membershipA = Membership::factory()->for($user)->for($teamA)->create();
    $membershipB = Membership::factory()->for($user)->for($teamB)->create();

    // Old season on Team A (should not count)
    $oldSlotA = Slot::factory()->for($oldSeasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-02 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-02 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($oldSlotA)->for($membershipA)->confirmed()->create();

    // Current season on Team A (3 h ended)
    $currentSlotA = Slot::factory()->for($currentSeasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 11:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($currentSlotA)->for($membershipA)->confirmed()->create();

    // Current season on Team B (5 h ended, should not count while viewing Team A)
    $currentSlotB = Slot::factory()->for($currentSeasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 17:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($currentSlotB)->for($membershipB)->confirmed()->create();

    $this->actingAs($user);

    $this->get(route('my-shifts.index'))
        ->assertOk()
        ->assertSeeInOrder(['data-test="my-planned-hours"', '3 h'], false)
        ->assertSeeInOrder(['data-test="my-worked-hours"', '3 h'], false);
});
