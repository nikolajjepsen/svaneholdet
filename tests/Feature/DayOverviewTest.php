<?php

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('allows an admin of the current team to access day overview and see the sidebar link', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('day-overview.index'))
        ->assertSee('Day overview');

    $this->actingAs($admin)
        ->get(route('day-overview.index'))
        ->assertOk()
        ->assertSee('Day overview')
        ->assertSee('Bar Team')
        ->assertSee(Season::DEFAULT_NAME);
});

it('denies volunteers, removed members, and admins of other teams from accessing day overview', function () {
    $teamA = Team::factory()->create(['name' => 'Team Alpha', 'timezone' => 'Europe/Copenhagen']);
    $seasonA = Season::factory()->for($teamA)->create(['name' => Season::DEFAULT_NAME]);
    $teamA->update(['current_season_id' => $seasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team Beta', 'timezone' => 'Europe/Copenhagen']);
    $seasonB = Season::factory()->for($teamB)->create(['name' => Season::DEFAULT_NAME]);
    $teamB->update(['current_season_id' => $seasonB->id]);

    // Volunteer on Team A
    $volunteer = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteer)->for($teamA)->create();

    $this->actingAs($volunteer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('day-overview.index'));

    $this->actingAs($volunteer)
        ->get(route('day-overview.index'))
        ->assertForbidden();

    // Admin of Team B who is only a volunteer on Team A cannot access Team A's day overview
    $otherTeamAdmin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($otherTeamAdmin)->for($teamB)->create();
    Membership::factory()->for($otherTeamAdmin)->for($teamA)->create();

    $this->actingAs($otherTeamAdmin)
        ->withSession(['current_team_id' => $teamA->id])
        ->get(route('day-overview.index'))
        ->assertForbidden();

    // Removed admin of Team A who still belongs to Team B as a volunteer
    $removedAdmin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->removed()->for($removedAdmin)->for($teamA)->create();
    Membership::factory()->for($removedAdmin)->for($teamB)->create();

    $this->actingAs($removedAdmin)
        ->withSession(['current_team_id' => $teamA->id])
        ->get(route('day-overview.index'))
        ->assertForbidden();

    // Policy check directly: Team B admin cannot view Team A's day overview
    expect($otherTeamAdmin->can('viewDayOverview', $teamA))->toBeFalse();
    expect($otherTeamAdmin->can('viewDayOverview', $teamB))->toBeTrue();
    expect($removedAdmin->can('viewDayOverview', $teamA))->toBeFalse();
});

it('logs out a user with no active memberships left when visiting day overview', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $team = Team::factory()->create(['name' => 'Solo Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $membership = Membership::factory()->admin()->for($user)->for($team)->create();

    $this->actingAs($user)
        ->get(route('day-overview.index'))
        ->assertOk();

    $membership->update(['status' => MembershipStatus::Removed]);

    $this->actingAs($user->refresh())
        ->get(route('day-overview.index'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('defaults to today in the team timezone when today has slots', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 10:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 14:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::day-overview.index')
        ->assertSet('selectedDate', '2027-08-05')
        ->assertSee('08:00–16:00')
        ->assertDontSee('10:00–14:00');
});

it('defaults to the earliest upcoming day with slots when today has none', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-02 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    // Past slot (should be skipped)
    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-01 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-01 12:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    // Later upcoming slot
    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-07 14:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-07 18:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    // Earliest upcoming slot
    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 09:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 13:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::day-overview.index')
        ->assertSet('selectedDate', '2027-08-04')
        ->assertSee('09:00–13:00')
        ->assertDontSee('14:00–18:00');
});

it('defaults to today when there are no upcoming slots', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-10 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    // Only a past slot exists
    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::day-overview.index')
        ->assertSet('selectedDate', '2027-08-10')
        ->assertSee('No slots on this day.');
});

it('navigates between days using previous day, next day, and the date picker', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 10:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 14:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 14:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 18:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-09 16:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-09 20:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 5,
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::day-overview.index')
        ->assertSet('selectedDate', '2027-08-05')
        ->assertSee('10:00–14:00')
        ->call('previousDay')
        ->assertSet('selectedDate', '2027-08-04')
        ->assertSee('08:00–12:00')
        ->assertDontSee('10:00–14:00')
        ->call('nextDay')
        ->call('nextDay')
        ->assertSet('selectedDate', '2027-08-06')
        ->assertSee('14:00–18:00')
        ->assertDontSee('08:00–12:00')
        ->set('selectedDate', '2027-08-09')
        ->assertSee('16:00–20:00')
        ->assertDontSee('14:00–18:00');
});

it('counts unique confirmed members once for the day and once per slot, and shows slot staffing details and links', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $alice = Membership::factory()->for(User::factory()->create(['name' => 'Alice']))->for($team)->create();
    $bob = Membership::factory()->for(User::factory()->create(['name' => 'Bob']))->for($team)->create();
    $clara = Membership::factory()->for(User::factory()->create(['name' => 'Clara']))->for($team)->create();
    $david = Membership::factory()->for(User::factory()->create(['name' => 'David']))->for($team)->create();

    $morningSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $afternoonSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 14:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 18:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    // Alice is confirmed on BOTH slots -> counts once in day total (unique), once on each slot.
    Shift::factory()->for($morningSlot)->for($alice)->confirmed()->create();
    Shift::factory()->for($afternoonSlot)->for($alice)->confirmed()->create();

    // Bob is confirmed on morning slot.
    Shift::factory()->for($morningSlot)->for($bob)->confirmed()->create();

    // Clara is pending on morning slot, rejected on afternoon slot (does not count in unique confirmed).
    Shift::factory()->for($morningSlot)->for($clara)->pending()->create();
    Shift::factory()->for($afternoonSlot)->for($clara)->rejected()->create();

    // David is cancelled on morning slot, pending on afternoon slot (does not count in unique confirmed).
    Shift::factory()->for($morningSlot)->for($david)->cancelled()->create();
    Shift::factory()->for($afternoonSlot)->for($david)->pending()->create();

    // Unique confirmed members for Aug 5: Alice and Bob = 2.
    // Morning slot: 2 / 3 confirmed, 1 pending.
    // Afternoon slot: 1 / 4 confirmed, 1 pending.
    $this->actingAs($admin)
        ->get(route('day-overview.index'))
        ->assertOk()
        ->assertSeeHtml('data-test="unique-confirmed-members-count"')
        ->assertSee('08:00–12:00')
        ->assertSee('14:00–18:00')
        ->assertSee('2 / 3')
        ->assertSee('1 / 4')
        ->assertSee('1 pending')
        ->assertSee(route('schedule.slots.show', $morningSlot))
        ->assertSee(route('schedule.slots.show', $afternoonSlot));

    Livewire::actingAs($admin)
        ->test('pages::day-overview.index')
        ->assertViewHas('uniqueConfirmedMembersCount', 2);
});

it('handles half-open midnight boundaries for 16:00-00:00 and 22:00-02:00 slots', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $alice = Membership::factory()->for(User::factory()->create(['name' => 'Alice']))->for($team)->create();
    $bob = Membership::factory()->for(User::factory()->create(['name' => 'Bob']))->for($team)->create();

    // Ends at exact midnight: 2027-08-05 16:00 -> 2027-08-06 00:00 (only on Aug 5)
    $midnightSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 16:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 00:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    // Spans past midnight: 2027-08-05 22:00 -> 2027-08-06 02:00 (on BOTH Aug 5 and Aug 6)
    $overnightSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 22:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 02:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    Shift::factory()->for($midnightSlot)->for($alice)->confirmed()->create();
    Shift::factory()->for($overnightSlot)->for($bob)->confirmed()->create();

    $this->actingAs($admin);

    // On Aug 5: both slots appear, unique confirmed members = 2 (Alice + Bob).
    // 16:00–00:00 ends at exact midnight so it must NOT display "Ends next day"; 22:00–02:00 must.
    $component = Livewire::test('pages::day-overview.index')
        ->assertSet('selectedDate', '2027-08-05')
        ->assertSee('16:00–00:00')
        ->assertSee('22:00–02:00')
        ->assertSee('Ends next day')
        ->assertViewHas('uniqueConfirmedMembersCount', 2);

    expect(substr_count($component->html(), 'Ends next day'))->toBe(1);

    // On Aug 6: ONLY 22:00–02:00 appears (16:00–00:00 must NOT appear), unique confirmed members = 1 (Bob).
    $component
        ->call('nextDay')
        ->assertSet('selectedDate', '2027-08-06')
        ->assertDontSee('16:00–00:00')
        ->assertSee('22:00–02:00')
        ->assertSee('Started previous day')
        ->assertViewHas('uniqueConfirmedMembersCount', 1);
});

it('polls every 10 seconds with wire:poll and refreshes live counts without lazy-loading errors', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = Membership::factory()->for(User::factory()->create())->for($team)->create();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $this->actingAs($admin)
        ->get(route('day-overview.index'))
        ->assertOk()
        ->assertSeeHtml('wire:poll.10s');

    $component = Livewire::actingAs($admin)
        ->test('pages::day-overview.index')
        ->assertSee('0 / 2')
        ->assertViewHas('uniqueConfirmedMembersCount', 0);

    // Simulate another volunteer getting confirmed before the next poll cycle.
    Shift::factory()->for($slot)->for($volunteer)->confirmed()->create();

    $component
        ->call('$refresh')
        ->assertSee('1 / 2')
        ->assertViewHas('uniqueConfirmedMembersCount', 1);
});

it('isolates day overview to the current team and current season', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $teamA = Team::factory()->create(['name' => 'Team Alpha', 'timezone' => 'Europe/Copenhagen']);
    $currentSeasonA = Season::factory()->for($teamA)->create(['name' => 'Smukfest 2027']);
    $oldSeasonA = Season::factory()->for($teamA)->create(['name' => 'Smukfest 2026']);
    $teamA->update(['current_season_id' => $currentSeasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team Beta', 'timezone' => 'Europe/Copenhagen']);
    $seasonB = Season::factory()->for($teamB)->create(['name' => 'Smukfest 2027']);
    $teamB->update(['current_season_id' => $seasonB->id]);

    $adminA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $slotCurrent = Slot::factory()->for($currentSeasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 09:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 13:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    Slot::factory()->for($oldSeasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 14:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 18:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 19:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 23:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $this->actingAs($adminA)
        ->get(route('day-overview.index'))
        ->assertOk()
        ->assertSee('09:00–13:00')
        ->assertSee(route('schedule.slots.show', $slotCurrent))
        ->assertDontSee('14:00–18:00')
        ->assertDontSee('19:00–23:00');
});

it('renders day overview in Danish for Danish admins', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $team = Team::factory()->create(['name' => 'Barholdet', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'da']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $this->actingAs($admin)
        ->get(route('day-overview.index'))
        ->assertOk()
        ->assertSee('Dagsoversigt')
        ->assertSee('Forrige dag')
        ->assertSee('Næste dag')
        ->assertSee('Unikke bekræftede medlemmer')
        ->assertSee('Ingen vagter på denne dag.');
});
