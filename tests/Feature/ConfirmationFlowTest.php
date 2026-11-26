<?php

use App\Enums\ShiftStatus;
use App\Mail\ShiftConfirmedMail;
use App\Mail\ShiftRejectedMail;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function createConfirmationTeam(string $name = 'Bar Team', bool $requiresConfirmation = true): array
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

it('creates a pending shift that does not take a place and stays hidden from other volunteers', function () {
    [$team, $season] = createConfirmationTeam(requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 2,
    ]);

    $firstVolunteer = User::factory()->create(['name' => 'Freja Hansen', 'locale' => 'en']);
    $firstMembership = Membership::factory()->for($firstVolunteer)->for($team)->create();

    $secondVolunteer = User::factory()->create(['name' => 'Mikkel Holm', 'locale' => 'en']);
    Membership::factory()->for($secondVolunteer)->for($team)->create();

    $this->actingAs($firstVolunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $slot->id)
        ->assertHasNoErrors()
        ->assertSee('Your shift request has been sent.')
        ->assertSee('0 / 2')
        ->assertSee('Pending');

    $shift = Shift::query()->where('slot_id', $slot->id)->where('membership_id', $firstMembership->id)->sole();
    expect($shift->status)->toBe(ShiftStatus::Pending);
    expect($shift->confirmed_at)->toBeNull();

    // First volunteer sees their own pending shift on My shifts
    Livewire::test('pages::my-shifts.index')
        ->assertSee('Pending');

    // Second volunteer does NOT see the first volunteer's pending shift or name on Schedule
    $this->actingAs($secondVolunteer);

    Livewire::test('pages::schedule.index')
        ->assertSee('0 / 2')
        ->assertDontSee('Freja')
        ->assertDontSee('Pending')
        ->assertDontSee('1 pending');
});

it('warns before creating a pending shift on a full slot and saves once the volunteer accepts', function () {
    [$team, $season] = createConfirmationTeam(requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 1,
    ]);

    $existingVolunteer = User::factory()->create(['name' => 'Anna Holm', 'locale' => 'en']);
    $existingMembership = Membership::factory()->for($existingVolunteer)->for($team)->create();
    Shift::factory()->confirmed()->for($slot)->for($existingMembership)->create();

    $requestingVolunteer = User::factory()->create(['name' => 'Sofie Berg', 'locale' => 'en']);
    $requestingMembership = Membership::factory()->for($requestingVolunteer)->for($team)->create();

    $this->actingAs($requestingVolunteer);

    $component = Livewire::test('pages::schedule.index')
        ->assertSee('1 / 1')
        ->assertSee('Request shift')
        ->call('signUp', $slot->id)
        ->assertSet('showSignUpWarningModal', true)
        ->assertSee('This slot is already full.');

    expect(
        Shift::query()
            ->where('slot_id', $slot->id)
            ->where('membership_id', $requestingMembership->id)
            ->exists()
    )->toBeFalse();

    $component
        ->call('confirmSignUpWithWarnings')
        ->assertSet('showSignUpWarningModal', false)
        ->assertSee('Your shift request has been sent.')
        ->assertSee('Pending');

    $shift = Shift::query()
        ->where('slot_id', $slot->id)
        ->where('membership_id', $requestingMembership->id)
        ->sole();

    expect($shift->status)->toBe(ShiftStatus::Pending);
    expect($shift->confirmed_at)->toBeNull();
});

it('shows confirmed and pending shifts on the slot detail page only to admins of the slot team', function () {
    [$teamA, $seasonA] = createConfirmationTeam('Team A', requiresConfirmation: true);
    [$teamB] = createConfirmationTeam('Team B', requiresConfirmation: true);

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 3,
    ]);

    $adminA = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $adminB = User::factory()->create(['name' => 'Other Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($adminB)->for($teamB)->create();

    $confirmedVolunteer = User::factory()->create(['name' => 'Clara Confirmed', 'locale' => 'en']);
    $confirmedMembership = Membership::factory()->for($confirmedVolunteer)->for($teamA)->create();
    Shift::factory()->confirmed()->for($slotA)->for($confirmedMembership)->create();

    $pendingVolunteer = User::factory()->create(['name' => 'Peter Pending', 'locale' => 'en']);
    $pendingMembership = Membership::factory()->for($pendingVolunteer)->for($teamA)->create();
    Shift::factory()->pending()->for($slotA)->for($pendingMembership)->create();

    // Admin of Team A sees pending count on Schedule and can open Slot detail page
    $this->actingAs($adminA);

    Livewire::test('pages::schedule.index')
        ->assertSee('1 pending')
        ->assertSee(route('schedule.slots.show', $slotA));

    $this->get(route('schedule.slots.show', $slotA))
        ->assertOk()
        ->assertSee('1 / 3')
        ->assertSee('Clara Confirmed')
        ->assertSee('Peter Pending')
        ->assertSee('Confirm')
        ->assertSee('Reject');

    // Volunteer of Team A cannot open Slot detail page
    $this->actingAs($confirmedVolunteer)
        ->get(route('schedule.slots.show', $slotA))
        ->assertForbidden();

    // Admin of Team B cannot open Team A's Slot detail page
    $this->actingAs($adminB)
        ->get(route('schedule.slots.show', $slotA))
        ->assertForbidden();
});

it('lets an admin confirm a pending shift and emails the volunteer in their locale', function () {
    Mail::fake();

    [$team, $season] = createConfirmationTeam('Bar Team', requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Freja Hansen', 'email' => 'freja@example.com', 'locale' => 'da']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->pending()->for($slot)->for($membership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('confirmShift', $shift->id)
        ->assertHasNoErrors()
        ->assertSee('Shift confirmed.')
        ->assertSee('1 / 2');

    $shift->refresh();
    expect($shift->status)->toBe(ShiftStatus::Confirmed);
    expect($shift->confirmed_at)->not->toBeNull();

    Mail::assertSent(ShiftConfirmedMail::class, function (ShiftConfirmedMail $mail) use ($volunteer, $shift): bool {
        return $mail->hasTo($volunteer->email)
            && $mail->locale === 'da'
            && $mail->shift->is($shift);
    });
});

it('sends no email when an admin confirms their own pending shift', function () {
    Mail::fake();

    [$team, $season] = createConfirmationTeam('Bar Team', requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($team)->create();
    $shift = Shift::factory()->pending()->for($slot)->for($adminMembership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('confirmShift', $shift->id)
        ->assertHasNoErrors()
        ->assertSee('Shift confirmed.');

    expect($shift->fresh()->status)->toBe(ShiftStatus::Confirmed);
    Mail::assertNothingSent();
});

it('refuses confirming a pending shift when the slot is already full', function () {
    Mail::fake();

    [$team, $season] = createConfirmationTeam('Bar Team', requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 1,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $confirmedVolunteer = User::factory()->create(['locale' => 'en']);
    $confirmedMembership = Membership::factory()->for($confirmedVolunteer)->for($team)->create();
    Shift::factory()->confirmed()->for($slot)->for($confirmedMembership)->create();

    $pendingVolunteer = User::factory()->create(['locale' => 'en']);
    $pendingMembership = Membership::factory()->for($pendingVolunteer)->for($team)->create();
    $pendingShift = Shift::factory()->pending()->for($slot)->for($pendingMembership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('confirmShift', $pendingShift->id)
        ->assertHasErrors(['shift'])
        ->assertSee('This slot is full.');

    expect($pendingShift->fresh()->status)->toBe(ShiftStatus::Pending);
    expect($pendingShift->fresh()->confirmed_at)->toBeNull();
    Mail::assertNothingSent();
});

it('lets an admin reject a pending shift and emails the volunteer in their locale', function () {
    Mail::fake();

    [$team, $season] = createConfirmationTeam('Bar Team', requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Freja Hansen', 'email' => 'freja@example.com', 'locale' => 'da']);
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->pending()->for($slot)->for($membership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('rejectShift', $shift->id)
        ->assertHasNoErrors()
        ->assertSee('Shift rejected.');

    $shift->refresh();
    expect($shift->status)->toBe(ShiftStatus::Rejected);
    expect($shift->confirmed_at)->toBeNull();

    Mail::assertSent(ShiftRejectedMail::class, function (ShiftRejectedMail $mail) use ($volunteer, $shift): bool {
        return $mail->hasTo($volunteer->email)
            && $mail->locale === 'da'
            && $mail->shift->is($shift);
    });
});

it('sends no email when an admin rejects their own pending shift', function () {
    Mail::fake();

    [$team, $season] = createConfirmationTeam('Bar Team', requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($team)->create();
    $shift = Shift::factory()->pending()->for($slot)->for($adminMembership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('rejectShift', $shift->id)
        ->assertHasNoErrors()
        ->assertSee('Shift rejected.');

    expect($shift->fresh()->status)->toBe(ShiftStatus::Rejected);
    Mail::assertNothingSent();
});

it('forbids confirming or rejecting a shift from another team slot', function () {
    Mail::fake();

    [$teamA, $seasonA] = createConfirmationTeam('Team A', requiresConfirmation: true);
    [$teamB, $seasonB] = createConfirmationTeam('Team B', requiresConfirmation: true);

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 2,
    ]);
    $slotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 2,
    ]);

    $adminA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $volunteerB = User::factory()->create(['locale' => 'en']);
    $membershipB = Membership::factory()->for($volunteerB)->for($teamB)->create();
    $shiftB = Shift::factory()->pending()->for($slotB)->for($membershipB)->create();

    $this->actingAs($adminA);

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->call('confirmShift', $shiftB->id)
        ->assertForbidden();

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->call('rejectShift', $shiftB->id)
        ->assertForbidden();

    expect($shiftB->fresh()->status)->toBe(ShiftStatus::Pending);
    Mail::assertNothingSent();
});

it('leaves existing pending shifts pending when confirmation is turned off', function () {
    [$team, $season] = createConfirmationTeam('Bar Team', requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 3,
    ]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $firstVolunteer = User::factory()->create(['locale' => 'en']);
    $firstMembership = Membership::factory()->for($firstVolunteer)->for($team)->create();

    $secondVolunteer = User::factory()->create(['locale' => 'en']);
    $secondMembership = Membership::factory()->for($secondVolunteer)->for($team)->create();

    // First volunteer requests a shift while confirmation is on -> pending
    $this->actingAs($firstVolunteer);
    Livewire::test('pages::schedule.index')->call('signUp', $slot->id);

    $firstShift = Shift::query()->where('slot_id', $slot->id)->where('membership_id', $firstMembership->id)->sole();
    expect($firstShift->status)->toBe(ShiftStatus::Pending);

    // Admin turns confirmation off via Team settings
    $this->actingAs($admin);
    Livewire::test('pages::team.settings')
        ->set('shiftsRequireConfirmation', false)
        ->call('save')
        ->assertHasNoErrors();

    // Existing pending shift stays pending
    expect($firstShift->fresh()->status)->toBe(ShiftStatus::Pending);
    expect($firstShift->fresh()->confirmed_at)->toBeNull();

    // Second volunteer signs up after confirmation is off -> confirmed immediately
    $this->actingAs($secondVolunteer);
    Livewire::test('pages::schedule.index')->call('signUp', $slot->id);

    $secondShift = Shift::query()->where('slot_id', $slot->id)->where('membership_id', $secondMembership->id)->sole();
    expect($secondShift->status)->toBe(ShiftStatus::Confirmed);
    expect($secondShift->confirmed_at)->not->toBeNull();
});

it('lets a volunteer withdraw a pending shift at any time and request again without sending email', function () {
    Mail::fake();

    [$teamA, $seasonA] = createConfirmationTeam('Team A', requiresConfirmation: true);
    [$teamB] = createConfirmationTeam('Team B', requiresConfirmation: true);

    // Slot starts in 2 hours — well inside the 48-hour cancellation deadline
    $slot = Slot::factory()->for($seasonA)->create([
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(6),
        'capacity' => 2,
    ]);

    $volunteer = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($volunteer)->for($teamA)->create();

    $otherTeamVolunteer = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($otherTeamVolunteer)->for($teamB)->create();

    // Volunteer requests shift -> pending
    $this->actingAs($volunteer);
    Livewire::test('pages::schedule.index')
        ->call('signUp', $slot->id)
        ->assertHasNoErrors();

    $shift = Shift::query()->where('slot_id', $slot->id)->where('membership_id', $membership->id)->sole();
    expect($shift->status)->toBe(ShiftStatus::Pending);

    // Another team's volunteer cannot withdraw it
    $this->actingAs($otherTeamVolunteer);
    Livewire::test('pages::my-shifts.index')
        ->call('withdrawShift', $shift->id)
        ->assertForbidden();

    // Volunteer withdraws their own pending shift from My shifts even inside the deadline
    $this->actingAs($volunteer);
    Livewire::test('pages::my-shifts.index')
        ->assertSee('Withdraw')
        ->call('withdrawShift', $shift->id)
        ->assertHasNoErrors();

    expect($shift->fresh()->status)->toBe(ShiftStatus::Cancelled);
    Mail::assertNothingSent();

    // Volunteer can request the slot again and withdraw from Schedule too
    Livewire::test('pages::schedule.index')
        ->call('signUp', $slot->id)
        ->assertHasNoErrors()
        ->assertSee('Withdraw');

    $secondShift = Shift::query()
        ->where('slot_id', $slot->id)
        ->where('membership_id', $membership->id)
        ->where('status', ShiftStatus::Pending)
        ->sole();

    Livewire::test('pages::schedule.index')
        ->call('withdrawShift', $secondShift->id)
        ->assertHasNoErrors();

    expect($secondShift->fresh()->status)->toBe(ShiftStatus::Cancelled);
    Mail::assertNothingSent();
});
