<?php

use App\Enums\ShiftStatus;
use App\Mail\ShiftCancelledMail;
use App\Mail\ShiftConfirmedMail;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use App\Scheduling\Slots;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function createAdminSlotTeam(string $name = 'Bar Team', bool $requiresConfirmation = false): array
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

it('lets an admin assign any active member, themselves included, confirmed at once regardless of confirmation setting', function () {
    Mail::fake();

    [$team, $season] = createAdminSlotTeam(requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 3,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();

    $removedVolunteer = User::factory()->create(['name' => 'Removed Person', 'locale' => 'en']);
    $removedMembership = Membership::factory()->removed()->for($removedVolunteer)->for($team)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->set('assignMembershipId', $volunteerMembership->id)
        ->call('assignMember')
        ->assertHasNoErrors()
        ->assertSee('Member assigned to slot.')
        ->assertSee('1 / 3')
        ->assertSee('Bob Volunteer');

    $volunteerShift = Shift::query()
        ->where('slot_id', $slot->id)
        ->where('membership_id', $volunteerMembership->id)
        ->sole();

    expect($volunteerShift->status)->toBe(ShiftStatus::Confirmed);
    expect($volunteerShift->confirmed_at)->not->toBeNull();

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->set('assignMembershipId', $adminMembership->id)
        ->call('assignMember')
        ->assertHasNoErrors()
        ->assertSee('2 / 3')
        ->assertSee('Alice Admin');

    $adminShift = Shift::query()
        ->where('slot_id', $slot->id)
        ->where('membership_id', $adminMembership->id)
        ->sole();

    expect($adminShift->status)->toBe(ShiftStatus::Confirmed);
    expect($adminShift->confirmed_at)->not->toBeNull();

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->set('assignMembershipId', $removedMembership->id)
        ->call('assignMember')
        ->assertHasErrors(['assignMembershipId']);

    Mail::assertNothingSent();
});

it('lets an admin cancel a member shift at any time, including after the deadline, and emails the member in their locale', function () {
    Mail::fake();

    [$team, $season] = createAdminSlotTeam();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(6),
        'capacity' => 3,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($team)->create();
    $adminShift = Shift::factory()->confirmed()->for($slot)->for($adminMembership)->create();

    $volunteer = User::factory()->create(['name' => 'Freja Hansen', 'email' => 'freja@example.com', 'locale' => 'da']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();
    $volunteerShift = Shift::factory()->confirmed()->for($slot)->for($volunteerMembership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('cancelShift', $volunteerShift->id)
        ->assertHasNoErrors()
        ->assertSee('Shift cancelled.')
        ->assertSee('1 / 3');

    expect($volunteerShift->fresh()->status)->toBe(ShiftStatus::Cancelled);

    Mail::assertSent(ShiftCancelledMail::class, function (ShiftCancelledMail $mail) use ($volunteer, $volunteerShift): bool {
        return $mail->hasTo($volunteer->email)
            && $mail->locale === 'da'
            && $mail->shift->is($volunteerShift);
    });

    Mail::fake();

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('cancelShift', $adminShift->id)
        ->assertHasNoErrors()
        ->assertSee('0 / 3');

    expect($adminShift->fresh()->status)->toBe(ShiftStatus::Cancelled);
    Mail::assertNothingSent();
});

it('warns before assigning past capacity and saves once the admin accepts', function () {
    [$team, $season] = createAdminSlotTeam();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 1,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $first = User::factory()->create(['name' => 'First Volunteer', 'locale' => 'en']);
    $firstMembership = Membership::factory()->for($first)->for($team)->create();
    Shift::factory()->confirmed()->for($slot)->for($firstMembership)->create([
        'confirmed_at' => now()->subMinutes(10),
    ]);

    $second = User::factory()->create(['name' => 'Second Volunteer', 'locale' => 'en']);
    $secondMembership = Membership::factory()->for($second)->for($team)->create();

    $this->actingAs($admin);

    $component = Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->set('assignMembershipId', $secondMembership->id)
        ->call('assignMember')
        ->assertSet('showOverCapacityModal', true)
        ->assertSee('over capacity');

    expect(
        Shift::query()
            ->where('slot_id', $slot->id)
            ->where('membership_id', $secondMembership->id)
            ->exists()
    )->toBeFalse();

    $component
        ->call('acceptOverCapacityWarning')
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Member assigned to slot.')
        ->assertSee('2 / 1')
        ->assertSee('Over capacity');

    $assignedShift = Shift::query()
        ->where('slot_id', $slot->id)
        ->where('membership_id', $secondMembership->id)
        ->sole();

    expect($assignedShift->status)->toBe(ShiftStatus::Confirmed);
    expect($assignedShift->confirmed_at)->not->toBeNull();
});

it('warns before confirming a pending shift on a full slot and confirms once accepted', function () {
    Mail::fake();

    [$team, $season] = createAdminSlotTeam(requiresConfirmation: true);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 1,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $first = User::factory()->create(['name' => 'First Volunteer', 'locale' => 'en']);
    $firstMembership = Membership::factory()->for($first)->for($team)->create();
    Shift::factory()->confirmed()->for($slot)->for($firstMembership)->create([
        'confirmed_at' => now()->subMinutes(10),
    ]);

    $pending = User::factory()->create(['name' => 'Pending Volunteer', 'email' => 'pending@example.com', 'locale' => 'en']);
    $pendingMembership = Membership::factory()->for($pending)->for($team)->create();
    $pendingShift = Shift::factory()->pending()->for($slot)->for($pendingMembership)->create();

    $this->actingAs($admin);

    $component = Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('confirmShift', $pendingShift->id)
        ->assertSet('showOverCapacityModal', true)
        ->assertSee('over capacity');

    expect($pendingShift->fresh()->status)->toBe(ShiftStatus::Pending);
    Mail::assertNothingSent();

    $component
        ->call('acceptOverCapacityWarning')
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Shift confirmed.')
        ->assertSee('2 / 1')
        ->assertSee('Over capacity');

    expect($pendingShift->fresh()->status)->toBe(ShiftStatus::Confirmed);
    expect($pendingShift->fresh()->confirmed_at)->not->toBeNull();

    Mail::assertSent(ShiftConfirmedMail::class, fn (ShiftConfirmedMail $mail): bool => $mail->hasTo($pending->email));
});

it('lets an admin change a single slot capacity and warns when lowering below confirmed count', function () {
    [$team, $season] = createAdminSlotTeam();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 3,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    foreach (range(1, 2) as $index) {
        $volunteer = User::factory()->create(['name' => "Volunteer {$index}", 'locale' => 'en']);
        $membership = Membership::factory()->for($volunteer)->for($team)->create();
        Shift::factory()->confirmed()->for($slot)->for($membership)->create([
            'confirmed_at' => now()->subMinutes(10 - $index),
        ]);
    }

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->set('capacity', 4)
        ->call('updateCapacity')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('2 / 4');

    expect($slot->fresh()->capacity)->toBe(4);

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->set('capacity', 2)
        ->call('updateCapacity')
        ->assertHasNoErrors()
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('2 / 2');

    expect($slot->fresh()->capacity)->toBe(2);

    $component = Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->set('capacity', 1)
        ->call('updateCapacity')
        ->assertSet('showOverCapacityModal', true)
        ->assertSee('over capacity');

    expect($slot->fresh()->capacity)->toBe(2);

    $component
        ->call('acceptOverCapacityWarning')
        ->assertSet('showOverCapacityModal', false)
        ->assertSee('Capacity updated.')
        ->assertSee('2 / 1')
        ->assertSee('Over capacity');

    expect($slot->fresh()->capacity)->toBe(1);
});

it('still refuses a volunteer signup on a full or overbooked slot', function () {
    [$team, $season] = createAdminSlotTeam(requiresConfirmation: false);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 1,
    ]);

    foreach (range(1, 2) as $index) {
        $existing = User::factory()->create(['locale' => 'en']);
        $existingMembership = Membership::factory()->for($existing)->for($team)->create();
        Shift::factory()->confirmed()->for($slot)->for($existingMembership)->create();
    }

    $volunteer = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteer)->for($team)->create();

    $this->actingAs($volunteer);

    Livewire::test('pages::schedule.index')
        ->call('signUp', $slot->id)
        ->assertHasErrors(['slot']);
});

it('shows overbooking count and moving over-capacity badge to admins, and caps count and names at capacity for volunteers', function () {
    [$team, $season] = createAdminSlotTeam();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => now()->addDays(10)->setTime(8, 0),
        'ends_at' => now()->addDays(10)->setTime(16, 0),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $first = User::factory()->create(['name' => 'Alpha Confirmed', 'locale' => 'en']);
    $firstMembership = Membership::factory()->for($first)->for($team)->create();
    $firstShift = Shift::factory()->confirmed()->for($slot)->for($firstMembership)->create([
        'confirmed_at' => now()->subMinutes(30),
    ]);

    $second = User::factory()->create(['name' => 'Bravo Confirmed', 'locale' => 'en']);
    $secondMembership = Membership::factory()->for($second)->for($team)->create();
    $secondShift = Shift::factory()->confirmed()->for($slot)->for($secondMembership)->create([
        'confirmed_at' => now()->subMinutes(20),
    ]);

    $third = User::factory()->create(['name' => 'Charlie Confirmed', 'locale' => 'en']);
    $thirdMembership = Membership::factory()->for($third)->for($team)->create();
    $thirdShift = Shift::factory()->confirmed()->for($slot)->for($thirdMembership)->create([
        'confirmed_at' => now()->subMinutes(10),
    ]);

    $fourth = User::factory()->create(['name' => 'Delta Confirmed', 'locale' => 'en']);
    $fourthMembership = Membership::factory()->for($fourth)->for($team)->create();
    $fourthShift = Shift::factory()->confirmed()->for($slot)->for($fourthMembership)->create([
        'confirmed_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($first);

    Livewire::test('pages::schedule.index')
        ->assertSee('2 / 2')
        ->assertDontSee('4 / 2')
        ->assertDontSee('Over capacity')
        ->assertSee('Alpha, Bravo')
        ->assertDontSee('Charlie')
        ->assertDontSee('Delta');

    $this->actingAs($admin);

    Livewire::test('pages::schedule.index')
        ->assertSee('4 / 2')
        ->assertSee('Alpha, Bravo, Charlie, Delta');

    $slotHtml = Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->assertSee('4 / 2')
        ->html();

    expect($slotHtml)->not->toContain("over-capacity-badge-{$firstShift->id}");
    expect($slotHtml)->not->toContain("over-capacity-badge-{$secondShift->id}");
    expect($slotHtml)->toContain("over-capacity-badge-{$thirdShift->id}");
    expect($slotHtml)->toContain("over-capacity-badge-{$fourthShift->id}");

    $afterCancelHtml = Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->call('cancelShift', $firstShift->id)
        ->assertSee('3 / 2')
        ->html();

    expect($afterCancelHtml)->not->toContain("over-capacity-badge-{$secondShift->id}");
    expect($afterCancelHtml)->not->toContain("over-capacity-badge-{$thirdShift->id}");
    expect($afterCancelHtml)->toContain("over-capacity-badge-{$fourthShift->id}");
});

it('forbids an admin on team A from assigning, cancelling, or changing capacity on team B', function () {
    Mail::fake();

    [$teamA, $seasonA] = createAdminSlotTeam('Team A');
    [$teamB, $seasonB] = createAdminSlotTeam('Team B');

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
    $shiftB = Shift::factory()->confirmed()->for($slotB)->for($membershipB)->create();

    $otherVolunteerB = User::factory()->create(['locale' => 'en']);
    $otherMembershipB = Membership::factory()->for($otherVolunteerB)->for($teamB)->create();

    $this->actingAs($adminA);

    $this->get(route('schedule.slots.show', $slotB))->assertForbidden();

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->set('assignMembershipId', $otherMembershipB->id)
        ->call('assignMember')
        ->assertForbidden();

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->call('cancelShift', $shiftB->id)
        ->assertForbidden();

    expect(fn () => app(Slots::class)->updateCapacity($slotB, $adminA, 5))
        ->toThrow(AuthorizationException::class);

    expect($shiftB->fresh()->status)->toBe(ShiftStatus::Confirmed);
    expect($slotB->fresh()->capacity)->toBe(2);
    Mail::assertNothingSent();
});
