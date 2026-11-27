<?php

use App\Mail\SlotDeletedMail;
use App\Mail\SlotTimeChangedMail;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\Slots;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * @return array{0: Team, 1: Season}
 */
function createSlotEditTeam(string $name): array
{
    $team = Team::factory()->create([
        'name' => $name,
        'timezone' => 'Europe/Copenhagen',
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    return [$team->fresh(), $season];
}

it('lets an admin change a single slot start and end and emails confirmed members except themselves', function () {
    Mail::fake();

    [$teamA, $seasonA] = createSlotEditTeam('Bar Team');
    [$teamB] = createSlotEditTeam('Stage Team');

    $slot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'email' => 'alice@example.com', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($teamA)->create();
    Shift::factory()->confirmed()->for($slot)->for($adminMembership)->create();

    $volunteer = User::factory()->create(['name' => 'Freja Hansen', 'email' => 'freja@example.com', 'locale' => 'da']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($teamA)->create();
    Membership::factory()->for($volunteer)->for($teamB)->create();
    Shift::factory()->confirmed()->for($slot)->for($volunteerMembership)->create();

    $pendingVolunteer = User::factory()->create(['name' => 'Pending Person', 'email' => 'pending@example.com', 'locale' => 'en']);
    $pendingMembership = Membership::factory()->for($pendingVolunteer)->for($teamA)->create();
    Shift::factory()->pending()->for($slot)->for($pendingMembership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->assertSet('startTime', '08:00')
        ->assertSet('endTime', '16:00')
        ->set('startTime', '10:00')
        ->set('endTime', '18:00')
        ->call('updateTimes')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Slot times updated.')
        ->assertSee('10:00–18:00');

    $freshSlot = $slot->fresh();
    expect($freshSlot->starts_at->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-04 10:00');
    expect($freshSlot->ends_at->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-04 18:00');

    Mail::assertSent(SlotTimeChangedMail::class, 1);
    Mail::assertSent(SlotTimeChangedMail::class, function (SlotTimeChangedMail $mail) use ($volunteer, $slot): bool {
        return $mail->hasTo($volunteer->email)
            && $mail->locale === 'da'
            && $mail->slot->is($slot)
            && $mail->oldStartsAt->setTimezone('Europe/Copenhagen')->format('H:i') === '08:00'
            && $mail->oldEndsAt->setTimezone('Europe/Copenhagen')->format('H:i') === '16:00'
            && $mail->newStartsAt->setTimezone('Europe/Copenhagen')->format('H:i') === '10:00'
            && $mail->newEndsAt->setTimezone('Europe/Copenhagen')->format('H:i') === '18:00';
    });
    Mail::assertNotSent(SlotTimeChangedMail::class, fn (SlotTimeChangedMail $mail): bool => $mail->hasTo($admin->email));
    Mail::assertNotSent(SlotTimeChangedMail::class, fn (SlotTimeChangedMail $mail): bool => $mail->hasTo($pendingVolunteer->email));
});

it('blocks a single slot time change when a confirmed member would overlap a shift on another team', function () {
    Mail::fake();

    [$teamA, $seasonA] = createSlotEditTeam('Bar Team');
    [$teamB, $seasonB] = createSlotEditTeam('Stage Team');

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $slotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'email' => 'bob@example.com', 'locale' => 'en']);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    $membershipB = Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->confirmed()->for($slotA)->for($membershipA)->create();
    Shift::factory()->confirmed()->for($slotB)->for($membershipB)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->set('startTime', '10:00')
        ->set('endTime', '16:00')
        ->call('updateTimes')
        ->assertHasErrors(['times'])
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Bob Volunteer')
        ->assertSee('Stage Team');

    expect($slotA->fresh()->starts_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('08:00');
    expect($slotA->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('12:00');
    Mail::assertNothingSent();
});

it('warns on a single slot time change that creates a same-team overlap and saves once accepted', function () {
    Mail::fake();

    [$teamA, $seasonA] = createSlotEditTeam('Bar Team');
    [$teamB] = createSlotEditTeam('Stage Team');

    $slotOne = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $slotTwo = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'email' => 'bob@example.com', 'locale' => 'en']);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->confirmed()->for($slotOne)->for($membershipA)->create();
    Shift::factory()->confirmed()->for($slotTwo)->for($membershipA)->create();

    $this->actingAs($admin);

    $component = Livewire::test('pages::schedule.slot', ['slot' => $slotOne])
        ->set('startTime', '10:00')
        ->set('endTime', '15:00')
        ->call('updateTimes')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', true)
        ->assertSee('Bob Volunteer')
        ->assertSee('14:00–18:00');

    expect($slotOne->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('12:00');
    Mail::assertNothingSent();

    $component
        ->call('acceptOverCapacityWarning')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Slot times updated.')
        ->assertSee('10:00–15:00');

    expect($slotOne->fresh()->starts_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('10:00');
    expect($slotOne->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('15:00');

    Mail::assertSent(SlotTimeChangedMail::class, 1);
    Mail::assertSent(SlotTimeChangedMail::class, fn (SlotTimeChangedMail $mail): bool => $mail->hasTo($volunteer->email));
});

it('deletes an upcoming slot with shifts, emails affected members when ticked, and sends nothing when unticked', function () {
    Mail::fake();

    [$teamA, $seasonA] = createSlotEditTeam('Bar Team');
    [$teamB] = createSlotEditTeam('Stage Team');

    $slotWithEmail = Slot::factory()->for($seasonA)->create([
        'starts_at' => now()->addDays(5)->setTime(8, 0),
        'ends_at' => now()->addDays(5)->setTime(16, 0),
        'capacity' => 4,
    ]);

    $slotWithoutEmail = Slot::factory()->for($seasonA)->create([
        'starts_at' => now()->addDays(6)->setTime(8, 0),
        'ends_at' => now()->addDays(6)->setTime(16, 0),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'email' => 'alice@example.com', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($teamA)->create();
    Shift::factory()->confirmed()->for($slotWithEmail)->for($adminMembership)->create();

    $confirmedVolunteer = User::factory()->create(['name' => 'Freja Confirmed', 'email' => 'freja@example.com', 'locale' => 'da']);
    $confirmedMembership = Membership::factory()->for($confirmedVolunteer)->for($teamA)->create();
    Membership::factory()->for($confirmedVolunteer)->for($teamB)->create();
    Shift::factory()->confirmed()->for($slotWithEmail)->for($confirmedMembership)->create();
    Shift::factory()->confirmed()->for($slotWithoutEmail)->for($confirmedMembership)->create();

    $pendingVolunteer = User::factory()->create(['name' => 'Mads Pending', 'email' => 'mads@example.com', 'locale' => 'en']);
    $pendingMembership = Membership::factory()->for($pendingVolunteer)->for($teamA)->create();
    Shift::factory()->pending()->for($slotWithEmail)->for($pendingMembership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slotWithEmail])
        ->assertSet('notifyMembersOnDelete', true)
        ->call('openDeleteModal')
        ->assertSet('showDeleteModal', true)
        ->assertSee('2 confirmed')
        ->assertSee('1 pending')
        ->call('deleteSlot')
        ->assertRedirect(route('schedule.index'));

    expect(Slot::query()->find($slotWithEmail->id))->toBeNull();
    expect(Shift::query()->where('slot_id', $slotWithEmail->id)->exists())->toBeFalse();

    Mail::assertSent(SlotDeletedMail::class, 2);
    Mail::assertSent(SlotDeletedMail::class, fn (SlotDeletedMail $mail): bool => $mail->hasTo($confirmedVolunteer->email) && $mail->locale === 'da');
    Mail::assertSent(SlotDeletedMail::class, fn (SlotDeletedMail $mail): bool => $mail->hasTo($pendingVolunteer->email) && $mail->locale === 'en');
    Mail::assertNotSent(SlotDeletedMail::class, fn (SlotDeletedMail $mail): bool => $mail->hasTo($admin->email));

    Mail::fake();

    Livewire::test('pages::schedule.slot', ['slot' => $slotWithoutEmail])
        ->call('openDeleteModal')
        ->set('notifyMembersOnDelete', false)
        ->call('deleteSlot')
        ->assertRedirect(route('schedule.index'));

    expect(Slot::query()->find($slotWithoutEmail->id))->toBeNull();
    Mail::assertNothingSent();
});

it('forbids deleting a slot that has already started', function () {
    Mail::fake();

    [$teamA, $seasonA] = createSlotEditTeam('Bar Team');
    [$teamB] = createSlotEditTeam('Stage Team');

    $startedSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addHours(4),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();
    Membership::factory()->for($admin)->for($teamB)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $startedSlot])
        ->call('deleteSlot')
        ->assertHasErrors(['slot']);

    expect(Slot::query()->find($startedSlot->id))->not->toBeNull();
    Mail::assertNothingSent();
});

it('forbids an admin on team A from editing times or deleting a slot on team B', function () {
    Mail::fake();

    [$teamA] = createSlotEditTeam('Bar Team');
    [$teamB, $seasonB] = createSlotEditTeam('Stage Team');

    $slotB = Slot::factory()->for($seasonB)->create([
        'starts_at' => now()->addDays(5)->setTime(8, 0),
        'ends_at' => now()->addDays(5)->setTime(16, 0),
        'capacity' => 4,
    ]);

    $adminA = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    expect(fn () => app(Slots::class)->updateTimes($slotB, $adminA, '10:00', '18:00'))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(Slots::class)->delete($slotB, $adminA, notifyMembers: true))
        ->toThrow(AuthorizationException::class);

    expect(Slot::query()->find($slotB->id))->not->toBeNull();
    Mail::assertNothingSent();
});
