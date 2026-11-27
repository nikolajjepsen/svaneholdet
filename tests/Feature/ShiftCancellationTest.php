<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use App\Scheduling\Shifts;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function makeTeamWithSeasonAndDeadline(string $name = 'Bar Team', int $deadlineHours = 48): array
{
    $team = Team::factory()->create([
        'name' => $name,
        'timezone' => 'Europe/Copenhagen',
        'settings' => new TeamSettings(cancellationDeadlineHours: $deadlineHours),
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    return [$team->fresh(), $season];
}

it('cancels a confirmed shift before the deadline, frees the place, sends no email, and allows signing up again', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-01 08:00:00', 'Europe/Copenhagen'));

    [$team, $season] = makeTeamWithSeasonAndDeadline('Bar Team', 48);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 1,
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();

    $shifts = app(Shifts::class);
    $shift = $shifts->signUp($slot, $volunteer)->shift;
    expect($shift)->not->toBeNull();

    $cancelled = $shifts->cancel($shift, $volunteer);

    expect($cancelled->status)->toBe(ShiftStatus::Cancelled);
    expect(Shift::query()->where('slot_id', $slot->id)->where('status', ShiftStatus::Confirmed)->count())->toBe(0);
    Mail::assertNothingSent();

    $newShift = $shifts->signUp($slot, $volunteer)->shift;
    expect($newShift)->not->toBeNull();

    expect($newShift->id)->not->toBe($shift->id);
    expect($newShift->status)->toBe(ShiftStatus::Confirmed);
    expect(Shift::query()->where('slot_id', $slot->id)->where('membership_id', $membership->id)->count())->toBe(2);
});

it('refuses cancelling a confirmed shift after the team cancellation deadline and allows up to the deadline', function () {
    [$team, $season] = makeTeamWithSeasonAndDeadline('Bar Team', 12);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->for($slot)->for($membership)->confirmed()->create();

    $shifts = app(Shifts::class);

    // 1 second after the 12-hour deadline (2027-08-03 20:00:01) -> refused
    $this->travelTo(CarbonImmutable::parse('2027-08-03 20:00:01', 'Europe/Copenhagen'));

    expect(fn () => $shifts->cancel($shift, $volunteer))
        ->toThrow(ValidationException::class);

    expect($shift->fresh()->status)->toBe(ShiftStatus::Confirmed);

    // Exactly at the 12-hour deadline (2027-08-03 20:00:00) -> allowed
    $this->travelTo(CarbonImmutable::parse('2027-08-03 20:00:00', 'Europe/Copenhagen'));

    $cancelled = $shifts->cancel($shift, $volunteer);
    expect($cancelled->status)->toBe(ShiftStatus::Cancelled);
});

it('withdraws a pending shift at any time even after the cancellation deadline and sends no email', function () {
    Mail::fake();
    [$team, $season] = makeTeamWithSeasonAndDeadline('Bar Team', 48);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $pendingShift = Shift::factory()->for($slot)->for($membership)->pending()->create();

    // Well past the 48-hour deadline (1 hour before start)
    $this->travelTo(CarbonImmutable::parse('2027-08-04 07:00:00', 'Europe/Copenhagen'));

    $shifts = app(Shifts::class);
    $withdrawn = $shifts->withdraw($pendingShift, $volunteer);

    expect($withdrawn->status)->toBe(ShiftStatus::Cancelled);
    Mail::assertNothingSent();

    // Can sign up again after withdrawing
    $newShift = $shifts->signUp($slot, $volunteer)->shift;
    expect($newShift?->status)->toBe(ShiftStatus::Confirmed);
});

it('refuses cancelling a non-confirmed shift and withdrawing a non-pending shift', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-01 08:00:00', 'Europe/Copenhagen'));
    [$team, $season] = makeTeamWithSeasonAndDeadline('Bar Team', 48);

    $slotA = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $slotB = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();

    $pendingShift = Shift::factory()->for($slotA)->for($membership)->pending()->create();
    $confirmedShift = Shift::factory()->for($slotB)->for($membership)->confirmed()->create();

    $shifts = app(Shifts::class);

    expect(fn () => $shifts->cancel($pendingShift, $volunteer))
        ->toThrow(ValidationException::class);

    expect(fn () => $shifts->withdraw($confirmedShift, $volunteer))
        ->toThrow(ValidationException::class);
});

it('only allows an active member to cancel or withdraw their own shifts across two teams', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-01 08:00:00', 'Europe/Copenhagen'));
    [$teamA, $seasonA] = makeTeamWithSeasonAndDeadline('Team A', 48);
    [$teamB, $seasonB] = makeTeamWithSeasonAndDeadline('Team B', 48);

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $slotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);

    $ownerA = User::factory()->create();
    $ownerAMembership = Membership::factory()->for($ownerA)->for($teamA)->create();

    $otherVolunteerA = User::factory()->create();
    Membership::factory()->for($otherVolunteerA)->for($teamA)->create();

    $adminA = User::factory()->create();
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $memberB = User::factory()->create();
    $memberBMembership = Membership::factory()->admin()->for($memberB)->for($teamB)->create();

    $removedUser = User::factory()->create();
    $removedMembership = Membership::factory()->removed()->for($removedUser)->for($teamA)->create();

    $confirmedShiftA = Shift::factory()->for($slotA)->for($ownerAMembership)->confirmed()->create()->load('membership');
    $pendingShiftB = Shift::factory()->for($slotB)->for($memberBMembership)->pending()->create()->load('membership');
    $removedShiftA = Shift::factory()->for($slotA)->for($removedMembership)->confirmed()->create()->load('membership');

    expect(Gate::forUser($ownerA)->allows('cancel', $confirmedShiftA))->toBeTrue();
    expect(Gate::forUser($ownerA)->allows('withdraw', $confirmedShiftA))->toBeTrue();
    expect(Gate::forUser($otherVolunteerA)->allows('cancel', $confirmedShiftA))->toBeFalse();
    expect(Gate::forUser($adminA)->allows('cancel', $confirmedShiftA))->toBeFalse();
    expect(Gate::forUser($memberB)->allows('cancel', $confirmedShiftA))->toBeFalse();
    expect(Gate::forUser($ownerA)->allows('withdraw', $pendingShiftB))->toBeFalse();
    expect(Gate::forUser($removedUser)->allows('cancel', $removedShiftA))->toBeFalse();

    $shifts = app(Shifts::class);

    expect(fn () => $shifts->cancel($confirmedShiftA, $otherVolunteerA))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $shifts->cancel($confirmedShiftA, $memberB))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $shifts->withdraw($pendingShiftB, $ownerA))
        ->toThrow(AuthorizationException::class);

    expect($confirmedShiftA->fresh()->status)->toBe(ShiftStatus::Confirmed);
    expect($pendingShiftB->fresh()->status)->toBe(ShiftStatus::Pending);
});

it('lets a volunteer cancel a confirmed shift from the schedule before the deadline and sign up again', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-01 08:00:00', 'Europe/Copenhagen'));

    [$team, $season] = makeTeamWithSeasonAndDeadline('Bar Team', 48);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 1,
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->for($slot)->for($membership)->confirmed()->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->assertSee('1 / 1')
        ->assertSee('Cancel shift')
        ->call('cancelShift', $shift->id)
        ->assertHasNoErrors()
        ->assertSee('Your shift has been cancelled.')
        ->assertSee('0 / 1')
        ->assertSee('Sign up')
        ->call('signUp', $slot->id)
        ->assertHasNoErrors()
        ->assertSee('1 / 1');

    expect($shift->fresh()->status)->toBe(ShiftStatus::Cancelled);
    expect(Shift::query()->where('slot_id', $slot->id)->where('status', ShiftStatus::Confirmed)->count())->toBe(1);
    Mail::assertNothingSent();
});

it('lets a volunteer cancel a confirmed shift from my shifts before the deadline', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-01 08:00:00', 'Europe/Copenhagen'));

    [$team, $season] = makeTeamWithSeasonAndDeadline('Bar Team', 48);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->for($slot)->for($membership)->confirmed()->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::my-shifts.index')
        ->assertSee('Confirmed')
        ->assertSee('Cancel shift')
        ->call('cancelShift', $shift->id)
        ->assertHasNoErrors()
        ->assertSee('Your shift has been cancelled.')
        ->assertSee('Cancelled')
        ->assertDontSee('Cancel shift');

    expect($shift->fresh()->status)->toBe(ShiftStatus::Cancelled);
    Mail::assertNothingSent();
});

it('tells the volunteer to contact an admin and refuses cancelling after the deadline on schedule and my shifts', function () {
    [$team, $season] = makeTeamWithSeasonAndDeadline('Bar Team', 24);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->for($slot)->for($membership)->confirmed()->create();

    // 23 hours before start (past 24-hour deadline)
    $this->travelTo(CarbonImmutable::parse('2027-08-03 09:00:00', 'Europe/Copenhagen'));

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->assertSee('Contact an admin to cancel.')
        ->assertDontSee('Cancel shift')
        ->call('cancelShift', $shift->id)
        ->assertHasErrors(['shift']);

    Livewire::test('pages::my-shifts.index')
        ->assertSee('Contact an admin to cancel.')
        ->assertDontSee('Cancel shift')
        ->call('cancelShift', $shift->id)
        ->assertHasErrors(['shift']);

    expect($shift->fresh()->status)->toBe(ShiftStatus::Confirmed);
});

it('lets a volunteer withdraw a pending shift from schedule and my shifts even after the deadline', function () {
    Mail::fake();
    [$team, $season] = makeTeamWithSeasonAndDeadline('Bar Team', 48);

    $slotA = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $slotB = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $shiftA = Shift::factory()->for($slotA)->for($membership)->pending()->create();
    $shiftB = Shift::factory()->for($slotB)->for($membership)->pending()->create();

    $this->travelTo(CarbonImmutable::parse('2027-08-04 07:00:00', 'Europe/Copenhagen'));

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->assertSee('Withdraw')
        ->call('withdrawShift', $shiftA->id)
        ->assertHasNoErrors()
        ->assertSee('Your shift request has been withdrawn.');

    Livewire::test('pages::my-shifts.index')
        ->assertSee('Withdraw')
        ->call('withdrawShift', $shiftB->id)
        ->assertHasNoErrors()
        ->assertSee('Your shift request has been withdrawn.');

    expect($shiftA->fresh()->status)->toBe(ShiftStatus::Cancelled);
    expect($shiftB->fresh()->status)->toBe(ShiftStatus::Cancelled);
    Mail::assertNothingSent();
});

it('forbids cancelling or withdrawing another members shift via schedule or my shifts across two teams', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-01 08:00:00', 'Europe/Copenhagen'));
    [$teamA, $seasonA] = makeTeamWithSeasonAndDeadline('Team A', 48);
    [$teamB, $seasonB] = makeTeamWithSeasonAndDeadline('Team B', 48);

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $slotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);

    $actor = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->for($actor)->for($teamA)->create();
    Membership::factory()->for($actor)->for($teamB)->create();

    $otherA = User::factory()->create(['locale' => 'en']);
    $otherAMembership = Membership::factory()->for($otherA)->for($teamA)->create();
    $otherAShift = Shift::factory()->for($slotA)->for($otherAMembership)->confirmed()->create();

    $ownBShift = Shift::factory()->for($slotB)->for(
        $actor->activeMemberships()->whereBelongsTo($teamB)->firstOrFail()
    )->confirmed()->create();

    $this->actingAs($actor);

    Livewire::test('pages::schedule.index')
        ->call('cancelShift', $otherAShift->id)
        ->assertForbidden();

    Livewire::test('pages::my-shifts.index')
        ->call('cancelShift', $otherAShift->id)
        ->assertForbidden();

    // Even actor's own shift on Team B cannot be cancelled while Team A is active
    Livewire::test('pages::my-shifts.index')
        ->call('cancelShift', $ownBShift->id)
        ->assertForbidden();

    expect($otherAShift->fresh()->status)->toBe(ShiftStatus::Confirmed);
    expect($ownBShift->fresh()->status)->toBe(ShiftStatus::Confirmed);
});
