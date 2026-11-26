<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

function createTeamWithSeason(string $name = 'Alpha Team', string $timezone = 'Europe/Copenhagen'): array
{
    $team = Team::factory()->create(['name' => $name, 'timezone' => $timezone]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    return [$team->fresh(), $season];
}

it('lets an admin create a single slot and displays times in the team timezone', function () {
    [$team, $season] = createTeamWithSeason('Bar Team', 'Europe/Copenhagen');
    [$otherTeam] = createTeamWithSeason('Stage Team');

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();
    Membership::factory()->for($admin)->for($otherTeam)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.index')
        ->set('date', '2027-08-04')
        ->set('startTime', '08:00')
        ->set('endTime', '16:00')
        ->set('capacity', 6)
        ->call('createSlot')
        ->assertHasNoErrors()
        ->assertSee('08:00–16:00')
        ->assertSee('0 / 6');

    $slot = Slot::query()->where('season_id', $season->id)->sole();
    expect($slot->starts_at->equalTo(CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc()))->toBeTrue();
    expect($slot->ends_at->equalTo(CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc()))->toBeTrue();
    expect($slot->capacity)->toBe(6);
});

it('creates an overnight slot ending the next day when end time is before start time', function () {
    [$team, $season] = createTeamWithSeason('Night Team', 'Europe/Copenhagen');

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.index')
        ->set('date', '2027-08-04')
        ->set('startTime', '20:00')
        ->set('endTime', '08:00')
        ->set('capacity', 4)
        ->call('createSlot')
        ->assertHasNoErrors()
        ->assertSee('20:00–08:00')
        ->assertSee('0 / 4');

    $slot = Slot::query()->where('season_id', $season->id)->sole();
    expect($slot->starts_at->equalTo(CarbonImmutable::parse('2027-08-04 20:00:00', 'Europe/Copenhagen')->utc()))->toBeTrue();
    expect($slot->ends_at->equalTo(CarbonImmutable::parse('2027-08-05 08:00:00', 'Europe/Copenhagen')->utc()))->toBeTrue();
});

it('rejects a slot with equal start and end times or capacity below one', function () {
    [$team] = createTeamWithSeason();
    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.index')
        ->set('date', '2027-08-04')
        ->set('startTime', '08:00')
        ->set('endTime', '08:00')
        ->set('capacity', 0)
        ->call('createSlot')
        ->assertHasErrors(['endTime', 'capacity']);

    expect(Slot::query()->count())->toBe(0);
});

it('forbids a volunteer from creating a slot', function () {
    [$team] = createTeamWithSeason();
    $volunteer = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteer)->for($team)->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->assertDontSee('Add slot')
        ->set('date', '2027-08-04')
        ->set('startTime', '08:00')
        ->set('endTime', '16:00')
        ->set('capacity', 4)
        ->call('createSlot')
        ->assertForbidden();

    expect(Slot::query()->count())->toBe(0);
});

it('shows only the current season slots day by day and groups same-time slots side by side', function () {
    [$team, $currentSeason] = createTeamWithSeason('Bar Team', 'Europe/Copenhagen');
    $oldSeason = Season::factory()->for($team)->create(['name' => 'Smukfest 2026']);

    $oldSlot = Slot::factory()->for($oldSeason)->create([
        'starts_at' => CarbonImmutable::parse('2026-08-05 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-08-05 14:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $firstSameTime = Slot::factory()->for($currentSeason)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $secondSameTime = Slot::factory()->for($currentSeason)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 5,
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteer)->for($team)->create();

    $this->actingAs($volunteer);

    $response = $this->get(route('schedule.index'));
    $response->assertOk()
        ->assertSee('Smukfest 2027')
        ->assertSee('08:00–16:00')
        ->assertSee('0 / 3')
        ->assertSee('0 / 5')
        ->assertDontSee('10:00–14:00')
        ->assertSee('data-test="slot-card-'.$firstSameTime->id.'"', false)
        ->assertSee('data-test="slot-card-'.$secondSameTime->id.'"', false)
        ->assertSee('grid-cols-2', false)
        ->assertDontSee('data-test="slot-card-'.$oldSlot->id.'"', false);
});

it('follows the current team on the schedule across two teams', function () {
    [$teamA, $seasonA] = createTeamWithSeason('Team A');
    [$teamB, $seasonB] = createTeamWithSeason('Team B');

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 09:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $slotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 13:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 17:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 7,
    ]);

    $user = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->for($user)->for($teamA)->create();
    Membership::factory()->for($user)->for($teamB)->create();

    $this->actingAs($user);

    $this->get(route('schedule.index'))
        ->assertOk()
        ->assertSee('09:00–12:00')
        ->assertSee('data-test="slot-card-'.$slotA->id.'"', false)
        ->assertDontSee('data-test="slot-card-'.$slotB->id.'"', false);

    $this->post(route('current-team.update'), ['team_id' => $teamB->id])
        ->assertRedirect();

    $this->get(route('schedule.index'))
        ->assertOk()
        ->assertSee('13:00–17:00')
        ->assertSee('data-test="slot-card-'.$slotB->id.'"', false)
        ->assertDontSee('data-test="slot-card-'.$slotA->id.'"', false);
});

it('enforces season, slot and shift policies across two teams', function () {
    [$teamA, $seasonA] = createTeamWithSeason('Team A');
    [$teamB, $seasonB] = createTeamWithSeason('Team B');

    $slotA = Slot::factory()->for($seasonA)->create()->load('season');
    $slotB = Slot::factory()->for($seasonB)->create()->load('season');

    $adminA = User::factory()->create();
    $adminAMembership = Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $volunteerA = User::factory()->create();
    $volunteerAMembership = Membership::factory()->for($volunteerA)->for($teamA)->create();

    $volunteerB = User::factory()->create();
    $volunteerBMembership = Membership::factory()->for($volunteerB)->for($teamB)->create();

    $shiftA = Shift::factory()->for($slotA)->for($volunteerAMembership)->confirmed()->create()->load('membership');
    $shiftB = Shift::factory()->for($slotB)->for($volunteerBMembership)->confirmed()->create()->load('membership');

    // SeasonPolicy
    expect(Gate::forUser($adminA)->allows('view', $seasonA))->toBeTrue();
    expect(Gate::forUser($adminA)->allows('create', [Season::class, $teamA]))->toBeTrue();
    expect(Gate::forUser($adminA)->allows('update', $seasonA))->toBeTrue();
    expect(Gate::forUser($adminA)->allows('view', $seasonB))->toBeFalse();
    expect(Gate::forUser($adminA)->allows('update', $seasonB))->toBeFalse();
    expect(Gate::forUser($volunteerA)->allows('view', $seasonA))->toBeTrue();
    expect(Gate::forUser($volunteerA)->allows('create', [Season::class, $teamA]))->toBeFalse();

    // SlotPolicy
    expect(Gate::forUser($adminA)->allows('create', [Slot::class, $seasonA]))->toBeTrue();
    expect(Gate::forUser($adminA)->allows('create', [Slot::class, $seasonB]))->toBeFalse();
    expect(Gate::forUser($adminA)->allows('update', $slotA))->toBeTrue();
    expect(Gate::forUser($adminA)->allows('delete', $slotA))->toBeTrue();
    expect(Gate::forUser($adminA)->allows('update', $slotB))->toBeFalse();
    expect(Gate::forUser($volunteerA)->allows('view', $slotA))->toBeTrue();
    expect(Gate::forUser($volunteerA)->allows('signUp', $slotA))->toBeTrue();
    expect(Gate::forUser($volunteerA)->allows('signUp', $slotB))->toBeFalse();
    expect(Gate::forUser($volunteerA)->allows('create', [Slot::class, $seasonA]))->toBeFalse();

    // ShiftPolicy
    expect(Gate::forUser($volunteerA)->allows('view', $shiftA))->toBeTrue();
    expect(Gate::forUser($volunteerA)->allows('view', $shiftB))->toBeFalse();
    expect(Gate::forUser($adminA)->allows('view', $shiftA))->toBeTrue();
    expect(Gate::forUser($adminA)->allows('view', $shiftB))->toBeFalse();
    expect(Gate::forUser($adminA)->allows('update', $shiftA))->toBeTrue();
    expect(Gate::forUser($adminA)->allows('update', $shiftB))->toBeFalse();
    expect(Gate::forUser($volunteerA)->allows('update', $shiftA))->toBeFalse();
    expect($adminAMembership->id)->not->toBeNull();
});

it('lets a volunteer sign up for a slot with room and creates a confirmed shift', function () {
    [$team, $season] = createTeamWithSeason();
    $slot = Slot::factory()->for($season)->create(['capacity' => 2]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->assertSee('0 / 2')
        ->call('signUp', $slot->id)
        ->assertHasNoErrors()
        ->assertSee('You are signed up for this slot.')
        ->assertSee('1 / 2')
        ->assertSee('Signed up');

    $shift = Shift::query()->where('slot_id', $slot->id)->where('membership_id', $membership->id)->sole();
    expect($shift->status)->toBe(ShiftStatus::Confirmed);
    expect($shift->confirmed_at)->not->toBeNull();
});

it('refuses signup when the slot is full', function () {
    [$team, $season] = createTeamWithSeason();
    $slot = Slot::factory()->for($season)->create(['capacity' => 1]);

    $firstVolunteer = User::factory()->create(['locale' => 'en']);
    $firstMembership = Membership::factory()->for($firstVolunteer)->for($team)->create();
    Shift::factory()->for($slot)->for($firstMembership)->confirmed()->create();

    $secondVolunteer = User::factory()->create(['locale' => 'en']);
    $secondMembership = Membership::factory()->for($secondVolunteer)->for($team)->create();

    $this->actingAs($secondVolunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $slot->id)
        ->assertHasErrors(['slot']);

    expect(Shift::query()->where('slot_id', $slot->id)->where('membership_id', $secondMembership->id)->exists())->toBeFalse();
});

it('prevents a member from holding two active shifts on the same slot', function () {
    [$team, $season] = createTeamWithSeason();
    $slot = Slot::factory()->for($season)->create(['capacity' => 3]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    Shift::factory()->for($slot)->for($membership)->pending()->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $slot->id)
        ->assertHasErrors(['slot']);

    expect(Shift::query()->where('slot_id', $slot->id)->where('membership_id', $membership->id)->count())->toBe(1);
});

it('forbids signing up for a slot belonging to another team', function () {
    [$teamA] = createTeamWithSeason('Team A');
    [$teamB, $seasonB] = createTeamWithSeason('Team B');
    $slotB = Slot::factory()->for($seasonB)->create(['capacity' => 2]);

    $volunteer = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->for($volunteer)->for($teamA)->create();
    Membership::factory()->for($volunteer)->for($teamB)->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $slotB->id)
        ->assertForbidden();

    expect(Shift::query()->where('slot_id', $slotB->id)->exists())->toBeFalse();
});
