<?php

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\SeasonHourGoal;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function createHoursTeamWithSeason(string $name = 'Bar Team', string $timezone = 'Europe/Copenhagen'): array
{
    $team = Team::factory()->create(['name' => $name, 'timezone' => $timezone]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    return [$team->refresh(), $season];
}

it('allows an admin to access hours overview and shows the sidebar link', function () {
    [$team] = createHoursTeamWithSeason('Bar Team');
    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Hours overview')
        ->assertSee(route('hours.index'));

    $this->actingAs($admin)
        ->get(route('hours.index'))
        ->assertOk()
        ->assertSee('Hours overview')
        ->assertSee('Bar Team');
});

it('denies volunteers and admins of other teams from accessing hours overview', function () {
    [$teamA] = createHoursTeamWithSeason('Team Alpha');
    [$teamB] = createHoursTeamWithSeason('Team Beta');

    $volunteer = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteer)->for($teamA)->create();

    $otherAdmin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($otherAdmin)->for($teamB)->create();
    Membership::factory()->for($otherAdmin)->for($teamA)->create();

    $this->actingAs($volunteer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Hours overview');

    $this->actingAs($volunteer)
        ->get(route('hours.index'))
        ->assertForbidden();

    // Admin of Team B, volunteer on Team A: when current team is Team A, denied (403).
    $this->actingAs($otherAdmin)
        ->withSession(['current_team_id' => $teamA->id])
        ->get(route('hours.index'))
        ->assertForbidden();
});

it('logs out a removed admin who has no active memberships left when visiting hours overview', function () {
    [$team] = createHoursTeamWithSeason('Solo Team');
    $admin = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->admin()->for($admin)->for($team)->create();

    $this->actingAs($admin)
        ->get(route('hours.index'))
        ->assertOk();

    $membership->update(['status' => MembershipStatus::Removed]);

    $this->actingAs($admin->refresh())
        ->get(route('hours.index'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('isolates hours overview to the current team and current season', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen'));

    [$teamA, $seasonA] = createHoursTeamWithSeason('Team Alpha');
    [$teamB, $seasonB] = createHoursTeamWithSeason('Team Beta');
    $pastSeasonA = Season::factory()->for($teamA)->create(['name' => 'Smukfest 2026']);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();

    $bob = Membership::factory()->for(User::factory()->create(['name' => 'Bob Alpha']))->for($teamA)->create();
    $clara = Membership::factory()->for(User::factory()->create(['name' => 'Clara Beta']))->for($teamB)->create();

    // Current season shift for Bob on Team A: 2h
    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($slotA)->for($bob)->confirmed()->create();

    // Past season shift for Bob on Team A: 4h (must NOT count)
    $pastSlot = Slot::factory()->for($pastSeasonA)->create([
        'starts_at' => CarbonImmutable::parse('2026-08-05 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-08-05 14:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($pastSlot)->for($bob)->confirmed()->create();

    // Current season shift for Clara on Team B: 3h (must NOT appear)
    $slotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 13:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($slotB)->for($clara)->confirmed()->create();

    $response = $this->actingAs($admin)
        ->get(route('hours.index'))
        ->assertOk()
        ->assertSee('Bob Alpha')
        ->assertDontSee('Clara Beta')
        ->assertSee('2 h');

    $response->assertDontSee('6 h');
});

it('displays deduplicated planned and worked hours including shortened shifts, no-shows, and future cutoff', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 17:00', 'Europe/Copenhagen'));

    [$team, $season] = createHoursTeamWithSeason('Bar Team');
    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $bob = Membership::factory()->for(User::factory()->create(['name' => 'Bob Overlap']))->for($team)->create();
    $clara = Membership::factory()->for(User::factory()->create(['name' => 'Clara Adjusted']))->for($team)->create();
    $dan = Membership::factory()->for(User::factory()->create(['name' => 'Dan NoShow']))->for($team)->create();

    // Bob has two overlapping ended shifts: 10:00–14:00 and 12:00–16:00 -> union = 6 h planned & 6 h worked.
    // Plus a future unedited shift 2027-08-06 10:00–12:00 -> +2 h planned (8 h total), 0 worked (6 h total).
    // Plus pending/rejected/cancelled shifts -> must not count.
    $slot1 = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 14:00', 'Europe/Copenhagen')->utc(),
    ]);
    $slot2 = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
    ]);
    $futureSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen')->utc(),
    ]);
    $ignoredSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 06:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 09:00', 'Europe/Copenhagen')->utc(),
    ]);

    Shift::factory()->for($slot1)->for($bob)->confirmed()->create();
    Shift::factory()->for($slot2)->for($bob)->confirmed()->create();
    Shift::factory()->for($futureSlot)->for($bob)->confirmed()->create();
    Shift::factory()->for($ignoredSlot)->for($bob)->pending()->create();
    Shift::factory()->for($ignoredSlot)->for($bob)->rejected()->create();
    Shift::factory()->for($ignoredSlot)->for($bob)->cancelled()->create();

    // Clara has a shortened shift: 12:00–16:00 trimmed to 12:30–15:45 -> 4 h planned, 3 h 15 m worked.
    Shift::factory()->for($slot2)->for($clara)->adjusted(
        startsAt: CarbonImmutable::parse('2027-08-05 12:30', 'Europe/Copenhagen'),
        endsAt: CarbonImmutable::parse('2027-08-05 15:45', 'Europe/Copenhagen'),
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 16:15', 'Europe/Copenhagen'),
    )->create();

    // Dan has a No-show on 10:00–14:00 -> 4 h planned, 0 h worked.
    Shift::factory()->for($slot1)->for($dan)->noShow(
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 14:10', 'Europe/Copenhagen'),
    )->create();

    $html = $this->actingAs($admin)
        ->get(route('hours.index'))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('data-test="planned-hours-'.$bob->id.'"')
        ->toContain('8 h')
        ->toContain('6 h')
        ->toContain('3 h 15 m')
        ->toContain('0 h');

    // Verify exact per-member planned & worked values via Livewire
    Livewire::actingAs($admin)
        ->test('pages::hours.index')
        ->assertSeeInOrder(['Bob Overlap', '8 h', '6 h'])
        ->assertSeeInOrder(['Clara Adjusted', '4 h', '3 h 15 m'])
        ->assertSeeInOrder(['Dan NoShow', '4 h', '0 h']);
});

it('shows removed members with current-season shifts labeled as removed and supports status and name filters', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen'));

    [$team, $season] = createHoursTeamWithSeason('Bar Team');
    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $activeMember = Membership::factory()->for(User::factory()->create(['name' => 'Bob Active']))->for($team)->create();
    $removedWithShift = Membership::factory()->removed()->for(User::factory()->create(['name' => 'Clara Former']))->for($team)->create();
    Membership::factory()->removed()->for(User::factory()->create(['name' => 'Dan Ghost']))->for($team)->create();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 13:00', 'Europe/Copenhagen')->utc(),
    ]);
    Shift::factory()->for($slot)->for($removedWithShift)->confirmed()->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::hours.index')
        ->assertSee('Alice Admin')
        ->assertSee('Bob Active')
        ->assertSee('Clara Former')
        ->assertSeeHtml('data-test="removed-badge-'.$removedWithShift->id.'"')
        ->assertDontSee('Dan Ghost');

    // Status filter: Active
    $component->set('status', 'active')
        ->assertSee('Bob Active')
        ->assertDontSee('Clara Former');

    // Status filter: Removed
    $component->set('status', 'removed')
        ->assertSee('Clara Former')
        ->assertDontSee('Bob Active')
        ->assertDontSee('Alice Admin');

    // Name search (case-insensitive)
    $component->set('status', 'all')
        ->set('search', 'clara')
        ->assertSee('Clara Former')
        ->assertDontSee('Bob Active');

    // Clear filters
    $component->call('clearFilters')
        ->assertSee('Bob Active')
        ->assertSee('Clara Former');
});

it('filters by date range and attributes midnight-spanning shifts in full to their local start date', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-08 12:00', 'Europe/Copenhagen'));

    [$team, $season] = createHoursTeamWithSeason('Bar Team', 'Europe/Copenhagen');
    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $bob = Membership::factory()->for(User::factory()->create(['name' => 'Bob Night']))->for($team)->create();

    // Midnight-spanning slot: starts Aug 5 22:00, ends Aug 6 02:00 (4h) -> attributed in full to Aug 5
    $overnightSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 22:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 02:00', 'Europe/Copenhagen')->utc(),
    ]);
    // Daytime slot on Aug 6: 10:00–12:00 (2h) -> attributed to Aug 6
    $daySlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen')->utc(),
    ]);

    Shift::factory()->for($overnightSlot)->for($bob)->confirmed()->create();
    Shift::factory()->for($daySlot)->for($bob)->confirmed()->create();

    // Unfiltered: 6 h
    $component = Livewire::actingAs($admin)
        ->test('pages::hours.index')
        ->assertSeeInOrder(['Bob Night', '6 h', '6 h']);

    // Aug 5 only: includes overnight shift in full (4 h), excludes Aug 6 shift
    $component->set('fromDate', '2027-08-05')
        ->set('toDate', '2027-08-05')
        ->assertSeeInOrder(['Bob Night', '4 h', '4 h']);

    // Aug 6 only: excludes overnight shift that started Aug 5, includes Aug 6 shift (2 h)
    $component->set('fromDate', '2027-08-06')
        ->set('toDate', '2027-08-06')
        ->assertSeeInOrder(['Bob Night', '2 h', '2 h']);
});

it('filters short of goal using season progress alongside date, search, and membership status filters', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen'));

    [$team, $season] = createHoursTeamWithSeason();
    $team->update(['settings' => new TeamSettings(defaultHourGoal: 8)]);
    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($team)->create();

    $short = Membership::factory()->for(User::factory()->create(['name' => 'Lee Short']))->for($team)->create();
    $met = Membership::factory()->for(User::factory()->create(['name' => 'Lee Met']))->for($team)->create();
    $exceeded = Membership::factory()->for(User::factory()->create(['name' => 'Lee Exceeded']))->for($team)->create();
    $exempt = Membership::factory()->for(User::factory()->create(['name' => 'Lee Exempt']))->for($team)->create();
    $removed = Membership::factory()->removed()->for(User::factory()->create(['name' => 'Lee Removed']))->for($team)->create();

    SeasonHourGoal::factory()->for($season)->for($met)->create(['hours' => 4]);
    SeasonHourGoal::factory()->for($season)->for($exceeded)->create(['hours' => 3]);
    SeasonHourGoal::factory()->for($season)->for($exempt)->create(['hours' => 0]);

    $day = CarbonImmutable::parse('2027-08-05', $team->timezone);
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => $day->setTime(12, 0)->utc(),
        'ends_at' => $day->setTime(16, 0)->utc(),
    ]);

    foreach ([$short, $met, $exceeded, $exempt, $removed] as $member) {
        Shift::factory()->for($slot)->for($member)->confirmed()->create();
    }

    $this->actingAs($admin)->get(route('hours.index'))
        ->assertOk()
        ->assertSeeText('4 / 3 h')
        ->assertSeeText('4 / 4 h')
        ->assertSeeText('4 / 8 h')
        ->assertSee('data-test="short-of-goal-'.$short->id.'"', false)
        ->assertSee('data-test="short-of-goal-'.$adminMembership->id.'"', false)
        ->assertDontSee('data-test="short-of-goal-'.$met->id.'"', false)
        ->assertDontSee('data-test="short-of-goal-'.$exceeded->id.'"', false)
        ->assertDontSee('data-test="short-of-goal-'.$exempt->id.'"', false)
        ->assertDontSee('data-test="goal-progress-'.$exempt->id.'"', false);

    Livewire::actingAs($admin)->test('pages::hours.index')
        ->set('shortOfGoal', true)
        ->set('fromDate', $day->addDay()->toDateString())
        ->assertSee('Goal progress and the Short of goal filter cover the full season.')
        ->assertSeeHtmlInOrder(['Lee Short', 'Planned hours', '0 h', 'Worked hours', '0 h', '4 / 8 h'])
        ->assertSee('Lee Removed')
        ->assertDontSee('Lee Met')
        ->assertDontSee('Lee Exceeded')
        ->assertDontSee('Lee Exempt')
        ->set('status', 'active')
        ->assertDontSee('Lee Removed')
        ->set('search', 'lee')
        ->assertSee('Lee Short')
        ->assertDontSee('Alice Admin')
        ->call('clearFilters')
        ->assertSee('Lee Met')
        ->assertSee('Lee Exceeded')
        ->assertSee('Lee Exempt')
        ->assertSee('Lee Removed')
        ->assertDontSee('Goal progress and the Short of goal filter cover the full season.');
});

it('expands a member to reveal their shift history with planned times, actual times, no-show, duration, and audit metadata', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen'));

    [$team, $season] = createHoursTeamWithSeason('Bar Team', 'Europe/Copenhagen');
    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $bob = Membership::factory()->for(User::factory()->create(['name' => 'Bob History']))->for($team)->create();

    $plannedSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 09:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 11:00', 'Europe/Copenhagen')->utc(),
    ]);
    $adjustedSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
    ]);
    $noShowSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 18:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 20:00', 'Europe/Copenhagen')->utc(),
    ]);

    $plannedShift = Shift::factory()->for($plannedSlot)->for($bob)->confirmed()->create();
    $adjustedShift = Shift::factory()->for($adjustedSlot)->for($bob)->adjusted(
        startsAt: CarbonImmutable::parse('2027-08-05 12:30', 'Europe/Copenhagen'),
        endsAt: CarbonImmutable::parse('2027-08-05 15:45', 'Europe/Copenhagen'),
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 16:10', 'Europe/Copenhagen'),
    )->create();
    $noShowShift = Shift::factory()->for($noShowSlot)->for($bob)->noShow(
        recordedBy: $admin,
        recordedAt: CarbonImmutable::parse('2027-08-05 20:05', 'Europe/Copenhagen'),
    )->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::hours.index')
        ->assertDontSeeHtml('data-test="member-history-'.$bob->id.'"')
        ->call('toggleMember', $bob->id)
        ->assertSeeHtml('data-test="member-history-'.$bob->id.'"')
        ->assertSeeHtml('data-test="history-shift-'.$plannedShift->id.'"')
        ->assertSeeHtml('data-test="history-shift-'.$adjustedShift->id.'"')
        ->assertSeeHtml('data-test="history-shift-'.$noShowShift->id.'"')
        ->assertSee('09:00–11:00')
        ->assertSee('12:00–16:00')
        ->assertSee('12:30–15:45')
        ->assertSee('3 h 15 m')
        ->assertSee('No-show')
        ->assertSee('0 h')
        ->assertSee('Edited by Alice Admin on 5. Aug 2027 16:10');

    // Toggle again collapses it
    $component->call('toggleMember', $bob->id)
        ->assertDontSeeHtml('data-test="member-history-'.$bob->id.'"');
});

it('renders hours overview in Danish for Danish admins', function () {
    [$team] = createHoursTeamWithSeason('Barholdet');
    $admin = User::factory()->create(['name' => 'Anders Admin', 'locale' => 'da']);
    $membership = Membership::factory()->admin()->for($admin)->for($team)->create();

    $this->actingAs($admin)
        ->get(route('hours.index'))
        ->assertOk()
        ->assertSee('Timeoversigt')
        ->assertSee('Planlagte timer')
        ->assertSee('Arbejdede timer')
        ->assertSee('Medlemsstatus')
        ->assertSee('Vagthistorik')
        ->assertSeeText('Mangler timer')
        ->assertDontSee('data-test="goal-progress-'.$membership->id.'"', false)
        ->assertDontSee('data-test="short-of-goal-'.$membership->id.'"', false);
});
