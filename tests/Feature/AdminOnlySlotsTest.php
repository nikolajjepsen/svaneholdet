<?php

use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->season = Season::factory()->for($this->team)->create();
    $this->team->update(['current_season_id' => $this->season->id]);
});

it('creates an admin-only slot and keeps its audience fixed during edits', function () {
    $admin = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['locale' => 'en']))->create();

    $this->actingAs($admin->user);

    Livewire::test('pages::schedule.index')
        ->set('date', now()->addDays(3)->toDateString())
        ->set('isAdminOnly', true)
        ->call('createSlot')
        ->assertHasNoErrors()
        ->assertSee('Admin-only');

    $slot = Slot::query()->sole();

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->assertDontSeeHtml('wire:model="isAdminOnly"')
        ->set('capacity', 2)
        ->call('updateCapacity')
        ->assertHasNoErrors()
        ->assertSee('Admin-only');

    expect($slot->fresh()->is_admin_only)->toBeTrue();
});

it('rolls out a mix of slot audiences and preserves them through a series edit', function () {
    $admin = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['locale' => 'en']))->create();
    $dates = [now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString()];

    $this->actingAs($admin->user);
    $this->get(route('schedule.rollout'))->assertOk();

    Livewire::test('pages::schedule.rollout')
        ->set('selectedDates', $dates)
        ->set('intervals', [
            ['start' => '08:00', 'end' => '16:00', 'capacity' => 4],
            ['start' => '20:00', 'end' => '08:00', 'capacity' => 2, 'isAdminOnly' => true],
        ])
        ->assertSee('4 slots on 2 days')
        ->assertSee('Ends next day')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('schedule.index'));

    $adminSeries = SlotSeries::query()->where('local_start_time', '20:00')->sole();
    $regularSeries = SlotSeries::query()->where('local_start_time', '08:00')->sole();
    expect($adminSeries->is_admin_only)->toBeTrue()
        ->and($adminSeries->slots()->where('is_admin_only', true)->count())->toBe(2)
        ->and($regularSeries->is_admin_only)->toBeFalse()
        ->and($regularSeries->slots()->where('is_admin_only', false)->count())->toBe(2);

    $this->get(route('schedule.series.show', $adminSeries))->assertOk()->assertSeeText('Admin-only');

    Livewire::test('pages::schedule.series', ['slotSeries' => $adminSeries])
        ->assertDontSeeHtml('wire:model="isAdminOnly"')
        ->set('capacity', 3)
        ->call('saveSeries')
        ->assertHasNoErrors();

    expect($adminSeries->fresh()->is_admin_only)->toBeTrue()
        ->and($adminSeries->slots()->where('is_admin_only', true)->where('capacity', 3)->count())->toBe(2);
});

it('hides admin-only slots and denies their direct routes and signup to a volunteer', function () {
    $series = SlotSeries::factory()->for($this->season)->create(['is_admin_only' => true]);
    $slot = Slot::factory()->for($this->season)->for($series, 'series')->create(['is_admin_only' => true]);
    $regularSlot = Slot::factory()->for($this->season)->create();
    $volunteer = Membership::factory()->for($this->team)->create();
    Membership::factory()->admin()->for($volunteer->user)->create();

    $this->actingAs($volunteer->user)->withSession(['current_team_id' => $this->team->id]);

    $this->get(route('schedule.index'))->assertOk()
        ->assertSeeHtml('data-test="slot-card-'.$regularSlot->id.'"')
        ->assertDontSeeHtml('data-test="slot-card-'.$slot->id.'"');
    $this->get(route('schedule.slots.show', $slot))->assertForbidden();
    $this->get(route('schedule.series.show', $series))->assertForbidden();
    $this->get(route('schedule.rollout'))->assertForbidden();
    $this->get(route('day-overview.index'))->assertForbidden();

    Livewire::test('pages::schedule.index')->call('signUp', $slot->id)->assertForbidden();

    $this->assertDatabaseMissing(Shift::class, ['slot_id' => $slot->id, 'membership_id' => $volunteer->id]);
});

it('excludes and refuses a volunteer in the assignment picker on an admin-only slot', function () {
    $slot = Slot::factory()->for($this->season)->create(['is_admin_only' => true]);
    $admin = Membership::factory()->admin()->for($this->team)->create();
    $volunteer = Membership::factory()->for($this->team)
        ->for(User::factory()->create(['name' => 'Viktor Hansen']))->create();

    $this->actingAs($admin->user);
    $this->get(route('schedule.slots.show', $slot))->assertOk()
        ->assertDontSeeText('Viktor Hansen');

    Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->set('assignMembershipId', $volunteer->id)
        ->call('assignMember')
        ->assertHasErrors('assignMembershipId');

    $this->assertDatabaseMissing(Shift::class, ['slot_id' => $slot->id, 'membership_id' => $volunteer->id]);
});

it('warns before overbooking an admin-only slot and ranks confirmed admins by confirmation order', function (string $action) {
    $slot = Slot::factory()->for($this->season)->create([
        'is_admin_only' => true,
        'capacity' => 1,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(4),
    ]);
    $admin = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['locale' => 'en']))->create();
    $firstAdmin = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['name' => 'Astrid Andersen']))->create();
    $firstShift = Shift::factory()->confirmed()->for($slot)->for($firstAdmin)->create(['confirmed_at' => now()->subHour()]);

    Mail::fake();
    $pendingShift = $action === 'confirmation' ? Shift::factory()->pending()->for($slot)->for($admin)->create() : null;
    $this->actingAs($admin->user);

    if ($action === 'signup') {
        $page = Livewire::test('pages::schedule.index')->call('signUp', $slot->id)
            ->assertSet('showSignUpWarningModal', true);
        $accept = 'confirmSignUpWithWarnings';
    } else {
        $page = Livewire::test('pages::schedule.slot', ['slot' => $slot]);
        if ($pendingShift !== null) {
            $page->call('confirmShift', $pendingShift->id);
        } else {
            $page->set('assignMembershipId', $admin->id)->call('assignMember');
        }
        $page->assertSet('showOverCapacityModal', true);
        $accept = 'acceptOverCapacityWarning';
    }

    $page->assertSee('1 / 1')->assertSee('will put it over capacity');
    $this->assertDatabaseMissing(Shift::class, [
        'slot_id' => $slot->id,
        'membership_id' => $admin->id,
        'status' => ShiftStatus::Confirmed,
    ]);

    $page->call($accept)->assertHasNoErrors()->assertSee('2 / 1');
    $newShift = $slot->confirmedShifts()->where('membership_id', $admin->id)->sole();

    $this->get(route('schedule.slots.show', $slot))->assertOk()->assertSeeText('Admin-only')
        ->assertSee('over-capacity-badge-'.$newShift->id)
        ->assertDontSee('over-capacity-badge-'.$firstShift->id);

    $this->get(route('day-overview.index'))->assertOk()
        ->assertSeeTextInOrder(['Unique confirmed members', '2', 'Admin-only', 'Astrid', '2 / 1']);
})->with(['signup', 'assignment', 'confirmation']);

it('warns when lowering capacity below the number of confirmed admins', function (bool $editSeries) {
    $series = SlotSeries::factory()->for($this->season)->create(['is_admin_only' => true, 'capacity' => 2]);
    $slot = Slot::factory()->for($this->season)->for($series, 'series')->create([
        'is_admin_only' => true,
        'capacity' => 2,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(4),
    ]);
    $admin = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['locale' => 'en']))->create();
    $otherAdmin = Membership::factory()->admin()->for($this->team)->create();
    Shift::factory()->confirmed()->for($slot)->for($admin)->create();
    Shift::factory()->confirmed()->for($slot)->for($otherAdmin)->create();

    $this->actingAs($admin->user);

    if ($editSeries) {
        $page = Livewire::test('pages::schedule.series', ['slotSeries' => $series])
            ->set('capacity', 1)->call('saveSeries')->assertSet('showWarningModal', true);
        $accept = 'acceptWarnings';
    } else {
        $page = Livewire::test('pages::schedule.slot', ['slot' => $slot])
            ->set('capacity', 1)->call('updateCapacity')->assertSet('showOverCapacityModal', true);
        $accept = 'acceptOverCapacityWarning';
    }

    expect($slot->fresh()->capacity)->toBe(2);
    $page->call($accept)->assertHasNoErrors()->assertSee('2 / 1');
    expect($slot->fresh()->capacity)->toBe(1);
})->with(['single slot' => false, 'series' => true]);

it('silently cancels only upcoming admin-only shifts on demotion and preserves worked hours', function () {
    Mail::fake();
    $manager = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['locale' => 'en']))->create();
    $admin = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['name' => 'Astrid Andersen', 'locale' => 'en']))->create();
    $upcoming = Slot::factory()->for($this->season)->count(2)->create([
        'is_admin_only' => true,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(4),
    ]);
    $past = Slot::factory()->for($this->season)->create([
        'is_admin_only' => true,
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addHours(4),
    ]);
    $regularSlot = Slot::factory()->for($this->season)->create();

    $cancelled = Shift::factory()->for($upcoming[0])->for($admin)
        ->adjusted($upcoming[0]->starts_at->addHour(), $upcoming[0]->ends_at, $manager->user)->create();
    $pending = Shift::factory()->pending()->for($upcoming[1])->for($admin)->create();
    $pastShift = Shift::factory()->for($past)->for($admin)
        ->adjusted($past->starts_at->addHour(), $past->ends_at, $manager->user)->create();
    $regularShift = Shift::factory()->confirmed()->for($regularSlot)->for($admin)->create();

    $this->actingAs($manager->user);
    Livewire::test('pages::members.index')->call('demote', $admin->id)->assertHasNoErrors();

    $this->assertDatabaseHas(Shift::class, [
        'id' => $cancelled->id,
        'status' => ShiftStatus::Cancelled,
        'attendance_status' => AttendanceStatus::Planned,
        'actual_starts_at' => null,
        'actual_ends_at' => null,
        'attendance_recorded_by' => null,
        'attendance_recorded_at' => null,
    ]);
    expect($pending->fresh()->status)->toBe(ShiftStatus::Cancelled)
        ->and($pastShift->fresh()->status)->toBe(ShiftStatus::Confirmed)
        ->and($pastShift->fresh()->attendance_status)->toBe(AttendanceStatus::Adjusted)
        ->and($regularShift->fresh()->status)->toBe(ShiftStatus::Confirmed);

    $this->get(route('hours.index'))->assertOk()
        ->assertSeeTextInOrder(['Astrid Andersen', '12 h', '3 h']);
    $this->actingAs($admin->user)->get(route('my-shifts.index'))->assertOk()->assertSeeText('3 h');

    Mail::assertNothingSent();
});

it('refuses signup from an open warning after the admin is demoted', function () {
    $slot = Slot::factory()->for($this->season)->create(['is_admin_only' => true, 'capacity' => 1]);
    $manager = Membership::factory()->admin()->for($this->team)->create();
    $admin = Membership::factory()->admin()->for($this->team)->create();
    Shift::factory()->confirmed()->for($slot)->for($manager)->create();

    $this->actingAs($admin->user);
    $page = Livewire::test('pages::schedule.index')->call('signUp', $slot->id)
        ->assertSet('showSignUpWarningModal', true);

    $this->actingAs($manager->user);
    Livewire::test('pages::members.index')->call('demote', $admin->id)->assertHasNoErrors();

    $this->actingAs($admin->user);
    $page->call('confirmSignUpWithWarnings')->assertForbidden();
    $this->assertDatabaseMissing(Shift::class, ['slot_id' => $slot->id, 'membership_id' => $admin->id]);
});

it('rejects changing the admin-only flag on an existing slot or series', function (bool $editSeries) {
    $record = $editSeries
        ? SlotSeries::factory()->for($this->season)->create()
        : Slot::factory()->for($this->season)->create();

    expect(fn () => $record->update(['is_admin_only' => true]))->toThrow(LogicException::class);
    expect($record->fresh()->is_admin_only)->toBeFalse();
})->with(['single slot' => false, 'series' => true]);
