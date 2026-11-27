<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * @return array{0: Team, 1: Season}
 */
function createOverlapTeam(string $name, bool $requiresConfirmation = false): array
{
    $team = Team::factory()->create([
        'name' => $name,
        'timezone' => 'Europe/Copenhagen',
        'settings' => new TeamSettings(shiftsRequireConfirmation: $requiresConfirmation),
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    return [$team->fresh(), $season];
}

it('allows signing up for slots that only touch without any warning', function () {
    [$teamA, $seasonA] = createOverlapTeam('Bar Team');
    [$teamB, $seasonB] = createOverlapTeam('Stage Team');

    $morningA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $afternoonA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 20:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $eveningB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 20:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 02:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    $membershipB = Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->confirmed()->for($morningA)->for($membershipA)->create();
    Shift::factory()->confirmed()->for($eveningB)->for($membershipB)->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $afternoonA->id)
        ->assertHasNoErrors()
        ->assertSet('showSignUpWarningModal', false)
        ->assertSee('You are signed up for this slot.');

    expect(
        Shift::query()
            ->where('slot_id', $afternoonA->id)
            ->where('membership_id', $membershipA->id)
            ->where('status', ShiftStatus::Confirmed)
            ->exists()
    )->toBeTrue();
});

it('warns when signing up into a same-team overlap, names the other slot, and saves once accepted', function () {
    [$teamA, $seasonA] = createOverlapTeam('Bar Team');
    [$teamB] = createOverlapTeam('Stage Team');

    $existingSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $overlappingSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->confirmed()->for($existingSlot)->for($membershipA)->create();

    $this->actingAs($volunteer);

    $component = Livewire::test('pages::schedule.index')
        ->call('signUp', $overlappingSlot->id)
        ->assertHasNoErrors()
        ->assertSet('showSignUpWarningModal', true)
        ->assertSee('08:00–14:00');

    expect(
        Shift::query()
            ->where('slot_id', $overlappingSlot->id)
            ->where('membership_id', $membershipA->id)
            ->exists()
    )->toBeFalse();

    $component
        ->call('confirmSignUpWithWarnings')
        ->assertHasNoErrors()
        ->assertSet('showSignUpWarningModal', false)
        ->assertSee('You are signed up for this slot.');

    expect(
        Shift::query()
            ->where('slot_id', $overlappingSlot->id)
            ->where('membership_id', $membershipA->id)
            ->where('status', ShiftStatus::Confirmed)
            ->exists()
    )->toBeTrue();
});

it('blocks signing up into an overlap on another team', function () {
    [$teamA, $seasonA] = createOverlapTeam('Bar Team');
    [$teamB, $seasonB] = createOverlapTeam('Stage Team');

    $slotOnTeamB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $overlappingSlotOnTeamA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    $membershipB = Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->confirmed()->for($slotOnTeamB)->for($membershipB)->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $overlappingSlotOnTeamA->id)
        ->assertHasErrors(['slot'])
        ->assertSet('showSignUpWarningModal', false)
        ->assertSee('Stage Team');

    expect(
        Shift::query()
            ->where('slot_id', $overlappingSlotOnTeamA->id)
            ->where('membership_id', $membershipA->id)
            ->exists()
    )->toBeFalse();
});

it('warns when an admin assigns into a same-team overlap and saves once accepted', function () {
    [$teamA, $seasonA] = createOverlapTeam('Bar Team');
    [$teamB] = createOverlapTeam('Stage Team');

    $existingSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $overlappingSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->pending()->for($existingSlot)->for($membershipA)->create();

    $this->actingAs($admin);

    $component = Livewire::test('pages::schedule.slot', ['slot' => $overlappingSlot])
        ->set('assignMembershipId', $membershipA->id)
        ->call('assignMember')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', true)
        ->assertSee('08:00–14:00');

    expect(
        Shift::query()
            ->where('slot_id', $overlappingSlot->id)
            ->where('membership_id', $membershipA->id)
            ->exists()
    )->toBeFalse();

    $component
        ->call('acceptOverCapacityWarning')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Member assigned to slot.');

    expect(
        Shift::query()
            ->where('slot_id', $overlappingSlot->id)
            ->where('membership_id', $membershipA->id)
            ->where('status', ShiftStatus::Confirmed)
            ->exists()
    )->toBeTrue();
});

it('blocks an admin from assigning into an overlap on another team and names that team', function () {
    [$teamA, $seasonA] = createOverlapTeam('Bar Team');
    [$teamB, $seasonB] = createOverlapTeam('Stage Team');

    $slotOnTeamB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $overlappingSlotOnTeamA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    $membershipB = Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->pending()->for($slotOnTeamB)->for($membershipB)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $overlappingSlotOnTeamA])
        ->set('assignMembershipId', $membershipA->id)
        ->call('assignMember')
        ->assertHasErrors(['assignMembershipId'])
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Stage Team');

    expect(
        Shift::query()
            ->where('slot_id', $overlappingSlotOnTeamA->id)
            ->where('membership_id', $membershipA->id)
            ->exists()
    )->toBeFalse();
});

it('ignores cancelled shifts, rejected shifts, and shifts on removed memberships', function () {
    [$teamA, $seasonA] = createOverlapTeam('Bar Team');
    [$teamB, $seasonB] = createOverlapTeam('Stage Team');
    [$teamC, $seasonC] = createOverlapTeam('Gate Team');

    $targetSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $cancelledSlotOnTeamB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $rejectedSlotOnTeamB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $removedMembershipSlotOnTeamC = Slot::factory()->for($seasonC)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 11:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 15:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    $membershipB = Membership::factory()->for($volunteer)->for($teamB)->create();
    $removedMembershipC = Membership::factory()->removed()->for($volunteer)->for($teamC)->create();

    Shift::factory()->cancelled()->for($cancelledSlotOnTeamB)->for($membershipB)->create();
    Shift::factory()->rejected()->for($rejectedSlotOnTeamB)->for($membershipB)->create();
    Shift::factory()->confirmed()->for($removedMembershipSlotOnTeamC)->for($removedMembershipC)->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $targetSlot->id)
        ->assertHasNoErrors()
        ->assertSet('showSignUpWarningModal', false)
        ->assertSee('You are signed up for this slot.');

    expect(
        Shift::query()
            ->where('slot_id', $targetSlot->id)
            ->where('membership_id', $membershipA->id)
            ->where('status', ShiftStatus::Confirmed)
            ->exists()
    )->toBeTrue();
});

it('detects overlaps across midnight and shows both over-capacity and overlap warnings when assigning', function () {
    [$teamA, $seasonA] = createOverlapTeam('Bar Team');
    [$teamB, $seasonB] = createOverlapTeam('Stage Team');

    $overnightSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 20:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 04:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $earlyMorningSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 02:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 10:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 1,
    ]);

    $otherTeamOvernightSlot = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 02:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 06:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();

    $otherVolunteer = User::factory()->create(['name' => 'Clara Confirmed', 'locale' => 'en']);
    $otherMembership = Membership::factory()->for($otherVolunteer)->for($teamA)->create();
    Shift::factory()->confirmed()->for($earlyMorningSlot)->for($otherMembership)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en', 'last_chosen_team_id' => $teamB->id]);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    $membershipB = Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->confirmed()->for($overnightSlot)->for($membershipA)->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $otherTeamOvernightSlot->id)
        ->assertHasErrors(['slot'])
        ->assertSee('Bar Team');

    expect(
        Shift::query()
            ->where('slot_id', $otherTeamOvernightSlot->id)
            ->where('membership_id', $membershipB->id)
            ->exists()
    )->toBeFalse();

    $this->actingAs($admin);

    $component = Livewire::test('pages::schedule.slot', ['slot' => $earlyMorningSlot])
        ->set('assignMembershipId', $membershipA->id)
        ->call('assignMember')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', true)
        ->assertSee('over capacity')
        ->assertSee('20:00–04:00');

    $component
        ->call('acceptOverCapacityWarning')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Member assigned to slot.');

    expect(
        Shift::query()
            ->where('slot_id', $earlyMorningSlot->id)
            ->where('membership_id', $membershipA->id)
            ->where('status', ShiftStatus::Confirmed)
            ->exists()
    )->toBeTrue();
});

it('blocks confirming a pending shift that overlaps another team and warns on same-team overlap', function () {
    [$teamA, $seasonA] = createOverlapTeam('Bar Team', requiresConfirmation: true);
    [$teamB, $seasonB] = createOverlapTeam('Stage Team');

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 10:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $sameTeamSlotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 20:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $otherTeamSlotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $adminA = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    $membershipB = Membership::factory()->for($volunteer)->for($teamB)->create();

    $pendingShift = Shift::factory()->pending()->for($slotA)->for($membershipA)->create();
    $otherTeamShift = Shift::factory()->confirmed()->for($otherTeamSlotB)->for($membershipB)->create();

    $this->actingAs($adminA);

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->call('confirmShift', $pendingShift->id)
        ->assertHasErrors(['shift'])
        ->assertSee('Stage Team')
        ->assertSet('showOverCapacityModal', false);

    expect($pendingShift->fresh()->status)->toBe(ShiftStatus::Pending);

    $otherTeamShift->update(['status' => ShiftStatus::Cancelled]);
    Shift::factory()->confirmed()->for($sameTeamSlotA)->for($membershipA)->create();

    $component = Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->call('confirmShift', $pendingShift->id)
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', true)
        ->assertSee('14:00–20:00');

    expect($pendingShift->fresh()->status)->toBe(ShiftStatus::Pending);

    $component
        ->call('acceptOverCapacityWarning')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Shift confirmed.');

    expect($pendingShift->fresh()->status)->toBe(ShiftStatus::Confirmed);
});
