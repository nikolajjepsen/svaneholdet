<?php

use App\Auth\Memberships;
use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\Shifts;
use App\Scheduling\Slots;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function createAttendanceTeam(string $name = 'Bar Team', string $timezone = 'Europe/Copenhagen'): array
{
    $team = Team::factory()->create([
        'name' => $name,
        'timezone' => $timezone,
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    return [$team->fresh(), $season];
}

it('trims actual times on a confirmed shift and records audit metadata even before the slot starts', function () {
    $recordedAt = CarbonImmutable::parse('2027-08-04 10:00:00', 'Europe/Copenhagen');
    $this->travelTo($recordedAt);

    [$team, $season] = createAttendanceTeam();
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->confirmed()->for($slot)->for($volunteerMembership)->create();

    $updated = app(Shifts::class)->adjustAttendance($shift, $admin, '12:30', '15:45');

    expect($updated->attendance_status)->toBe(AttendanceStatus::Adjusted);
    expect($updated->actual_starts_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 12:30');
    expect($updated->actual_ends_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 15:45');
    expect($updated->attendance_recorded_by)->toBe($admin->id);
    expect($updated->attendance_recorded_at?->equalTo($recordedAt->utc()))->toBeTrue();
});

it('extends actual times earlier than planned start and later than planned end and updates latest editor', function () {
    $firstEdit = CarbonImmutable::parse('2027-08-05 18:00:00', 'Europe/Copenhagen');
    $this->travelTo($firstEdit);

    [$team, $season] = createAttendanceTeam();
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $firstAdmin = User::factory()->create(['name' => 'First Admin']);
    Membership::factory()->admin()->for($firstAdmin)->for($team)->create();

    $secondAdmin = User::factory()->create(['name' => 'Second Admin']);
    Membership::factory()->admin()->for($secondAdmin)->for($team)->create();

    $volunteer = User::factory()->create();
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->confirmed()->for($slot)->for($volunteerMembership)->create();

    app(Shifts::class)->adjustAttendance($shift, $firstAdmin, '11:30', '16:30');

    $secondEdit = CarbonImmutable::parse('2027-08-05 19:15:00', 'Europe/Copenhagen');
    $this->travelTo($secondEdit);

    $updated = app(Shifts::class)->adjustAttendance($shift, $secondAdmin, '11:15', '17:45');

    expect($updated->attendance_status)->toBe(AttendanceStatus::Adjusted);
    expect($updated->actual_starts_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 11:15');
    expect($updated->actual_ends_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 17:45');
    expect($updated->attendance_recorded_by)->toBe($secondAdmin->id);
    expect($updated->attendance_recorded_at?->equalTo($secondEdit->utc()))->toBeTrue();
});

it('resolves midnight-spanning actual times relative to the slot planned start', function () {
    [$team, $season] = createAttendanceTeam();
    $admin = User::factory()->create();
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create();
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();

    // Overnight slot 22:00–02:00, adjusted to 23:30–03:15
    $overnightSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 22:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 02:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);
    $overnightShift = Shift::factory()->confirmed()->for($overnightSlot)->for($volunteerMembership)->create();

    $adjusted1 = app(Shifts::class)->adjustAttendance($overnightShift, $admin, '23:30', '03:15');
    expect($adjusted1->actual_starts_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 23:30');
    expect($adjusted1->actual_ends_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-06 03:15');

    // Late arrival past midnight on 23:00–02:00 slot -> 00:15–02:30 next day
    $lateStartSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 23:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 02:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);
    $lateStartShift = Shift::factory()->confirmed()->for($lateStartSlot)->for($volunteerMembership)->create();

    $adjusted2 = app(Shifts::class)->adjustAttendance($lateStartShift, $admin, '00:15', '02:30');
    expect($adjusted2->actual_starts_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-06 00:15');
    expect($adjusted2->actual_ends_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-06 02:30');

    // Early arrival before midnight on 00:30–04:00 slot -> 23:45 previous day to 04:15
    $earlyStartSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 00:30', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 04:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);
    $earlyStartShift = Shift::factory()->confirmed()->for($earlyStartSlot)->for($volunteerMembership)->create();

    $adjusted3 = app(Shifts::class)->adjustAttendance($earlyStartShift, $admin, '23:45', '04:15');
    expect($adjusted3->actual_starts_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 23:45');
    expect($adjusted3->actual_ends_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-06 04:15');

    // Same-day slot 16:00–22:00 extended past midnight to 16:00–01:00
    $eveningSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 22:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);
    $eveningShift = Shift::factory()->confirmed()->for($eveningSlot)->for($volunteerMembership)->create();

    $adjusted4 = app(Shifts::class)->adjustAttendance($eveningShift, $admin, '16:00', '01:00');
    expect($adjusted4->actual_starts_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 16:00');
    expect($adjusted4->actual_ends_at?->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-06 01:00');

    // Equal start and end times are rejected (enforces > 0 and < 24h)
    expect(fn () => app(Shifts::class)->adjustAttendance($eveningShift, $admin, '16:00', '16:00'))
        ->toThrow(ValidationException::class);
});

it('marks a confirmed shift as no-show and resets an override back to planned', function () {
    $now = CarbonImmutable::parse('2027-08-05 14:00:00', 'Europe/Copenhagen');
    $this->travelTo($now);

    [$team, $season] = createAttendanceTeam();
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create();
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create();
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->confirmed()->for($slot)->for($volunteerMembership)->create();

    $shifts = app(Shifts::class);

    // Adjust first, then mark no-show: actual times must be cleared
    $shifts->adjustAttendance($shift, $admin, '12:30', '15:30');
    $noShow = $shifts->markNoShow($shift, $admin);

    expect($noShow->attendance_status)->toBe(AttendanceStatus::NoShow);
    expect($noShow->actual_starts_at)->toBeNull();
    expect($noShow->actual_ends_at)->toBeNull();
    expect($noShow->attendance_recorded_by)->toBe($admin->id);
    expect($noShow->attendance_recorded_at?->equalTo($now->utc()))->toBeTrue();

    // Reset to planned clears override and audit metadata
    $reset = $shifts->resetAttendance($shift, $admin);

    expect($reset->attendance_status)->toBe(AttendanceStatus::Planned);
    expect($reset->actual_starts_at)->toBeNull();
    expect($reset->actual_ends_at)->toBeNull();
    expect($reset->attendance_recorded_by)->toBeNull();
    expect($reset->attendance_recorded_at)->toBeNull();
});

it('rejects attendance edits on non-confirmed shifts', function (ShiftStatus $status) {
    [$team, $season] = createAttendanceTeam();
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create();
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create();
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();
    $shift = Shift::factory()->for($slot)->for($volunteerMembership)->create([
        'status' => $status,
        'confirmed_at' => null,
    ]);

    $shifts = app(Shifts::class);

    expect(fn () => $shifts->adjustAttendance($shift, $admin, '12:30', '15:30'))
        ->toThrow(ValidationException::class);
    expect(fn () => $shifts->markNoShow($shift, $admin))
        ->toThrow(ValidationException::class);
    expect(fn () => $shifts->resetAttendance($shift, $admin))
        ->toThrow(ValidationException::class);
})->with([
    'pending' => [ShiftStatus::Pending],
    'rejected' => [ShiftStatus::Rejected],
    'cancelled' => [ShiftStatus::Cancelled],
]);

it('clears attendance overrides when a confirmed shift is cancelled or membership is removed', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-01 12:00:00', 'Europe/Copenhagen'));

    [$team, $season] = createAttendanceTeam();
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-10 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-10 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 5,
    ]);

    $admin = User::factory()->create();
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $shifts = app(Shifts::class);

    // 1. Admin cancels an adjusted shift
    $v1 = User::factory()->create();
    $m1 = Membership::factory()->for($v1)->for($team)->create();
    $s1 = Shift::factory()->confirmed()->for($slot)->for($m1)->create();
    $shifts->adjustAttendance($s1, $admin, '12:30', '15:30');
    $shifts->cancelByAdmin($s1, $admin);

    $s1->refresh();
    expect($s1->status)->toBe(ShiftStatus::Cancelled);
    expect($s1->attendance_status)->toBe(AttendanceStatus::Planned);
    expect($s1->actual_starts_at)->toBeNull();
    expect($s1->actual_ends_at)->toBeNull();
    expect($s1->attendance_recorded_by)->toBeNull();
    expect($s1->attendance_recorded_at)->toBeNull();

    // 2. Volunteer cancels their own no-show shift before deadline
    $v2 = User::factory()->create();
    $m2 = Membership::factory()->for($v2)->for($team)->create();
    $s2 = Shift::factory()->confirmed()->for($slot)->for($m2)->create();
    $shifts->markNoShow($s2, $admin);
    $shifts->cancel($s2, $v2);

    $s2->refresh();
    expect($s2->status)->toBe(ShiftStatus::Cancelled);
    expect($s2->attendance_status)->toBe(AttendanceStatus::Planned);
    expect($s2->attendance_recorded_by)->toBeNull();
    expect($s2->attendance_recorded_at)->toBeNull();

    // 3. Removing a membership cancels upcoming shifts and clears attendance override
    $v3 = User::factory()->create();
    $m3 = Membership::factory()->for($v3)->for($team)->create();
    $s3 = Shift::factory()->confirmed()->for($slot)->for($m3)->create();
    $shifts->adjustAttendance($s3, $admin, '12:15', '15:45');
    app(Memberships::class)->remove($m3, $admin);

    $s3->refresh();
    expect($s3->status)->toBe(ShiftStatus::Cancelled);
    expect($s3->attendance_status)->toBe(AttendanceStatus::Planned);
    expect($s3->actual_starts_at)->toBeNull();
    expect($s3->actual_ends_at)->toBeNull();
    expect($s3->attendance_recorded_by)->toBeNull();
    expect($s3->attendance_recorded_at)->toBeNull();
});

it('preserves adjusted actual times when slot planned times change while unedited shifts follow the slot', function () {
    Mail::fake();

    [$team, $season] = createAttendanceTeam();
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create();
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $adjustedMember = Membership::factory()->for(User::factory()->create())->for($team)->create();
    $adjustedShift = Shift::factory()->confirmed()->for($slot)->for($adjustedMember)->create();
    app(Shifts::class)->adjustAttendance($adjustedShift, $admin, '12:30', '15:45');

    $plannedMember = Membership::factory()->for(User::factory()->create())->for($team)->create();
    $plannedShift = Shift::factory()->confirmed()->for($slot)->for($plannedMember)->create();

    app(Slots::class)->updateTimes($slot, $admin, '13:00', '17:00');

    $slot->refresh();
    $adjustedShift->refresh();
    $plannedShift->refresh();

    expect($slot->starts_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('13:00');
    expect($slot->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('17:00');

    expect($adjustedShift->attendance_status)->toBe(AttendanceStatus::Adjusted);
    expect($adjustedShift->actual_starts_at?->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('12:30');
    expect($adjustedShift->actual_ends_at?->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('15:45');

    expect($plannedShift->attendance_status)->toBe(AttendanceStatus::Planned);
    expect($plannedShift->actual_starts_at)->toBeNull();
    expect($plannedShift->actual_ends_at)->toBeNull();
});

it('authorizes attendance actions only for admins of the slot team', function () {
    [$teamA, $seasonA] = createAttendanceTeam('Team Alpha');
    [$teamB] = createAttendanceTeam('Team Beta');

    $slotA = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $adminA = User::factory()->create();
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $volunteerA = User::factory()->create();
    $volunteerMembershipA = Membership::factory()->for($volunteerA)->for($teamA)->create();
    $shiftA = Shift::factory()->confirmed()->for($slotA)->for($volunteerMembershipA)->create();

    $adminB = User::factory()->create();
    Membership::factory()->admin()->for($adminB)->for($teamB)->create();

    $removedAdminA = User::factory()->create();
    Membership::factory()->admin()->removed()->for($removedAdminA)->for($teamA)->create();

    $shifts = app(Shifts::class);

    foreach ([$volunteerA, $adminB, $removedAdminA] as $unauthorized) {
        expect(fn () => $shifts->adjustAttendance($shiftA, $unauthorized, '12:30', '15:30'))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $shifts->markNoShow($shiftA, $unauthorized))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $shifts->resetAttendance($shiftA, $unauthorized))
            ->toThrow(AuthorizationException::class);
    }

    // HTTP route boundary check
    $this->actingAs($volunteerA)
        ->get(route('schedule.slots.show', $slotA))
        ->assertForbidden();

    $this->actingAs($adminB)
        ->get(route('schedule.slots.show', $slotA))
        ->assertForbidden();

    $this->actingAs($adminA)
        ->get(route('schedule.slots.show', $slotA))
        ->assertOk();
});

it('manages inline attendance controls, audit display, and slot-time refresh on the slot detail page', function () {
    $now = CarbonImmutable::parse('2027-08-05 16:30:00', 'Europe/Copenhagen');
    $this->travelTo($now);
    Mail::fake();

    [$team, $season] = createAttendanceTeam();
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $bob = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $bobMembership = Membership::factory()->for($bob)->for($team)->create();
    $bobShift = Shift::factory()->confirmed()->for($slot)->for($bobMembership)->create();

    $carol = User::factory()->create(['name' => 'Carol Volunteer', 'locale' => 'en']);
    $carolMembership = Membership::factory()->for($carol)->for($team)->create();
    $carolShift = Shift::factory()->confirmed()->for($slot)->for($carolMembership)->create();

    $this->actingAs($admin);

    $component = Livewire::test('pages::schedule.slot', ['slot' => $slot])
        ->assertSet("attendanceTimes.{$bobShift->id}.startTime", '12:00')
        ->assertSet("attendanceTimes.{$bobShift->id}.endTime", '16:00')
        ->assertSet("attendanceTimes.{$carolShift->id}.startTime", '12:00')
        ->assertSet("attendanceTimes.{$carolShift->id}.endTime", '16:00')
        ->assertDontSee('Reset to planned');

    // Adjust Bob's times: 12:30–15:45
    $component
        ->set("attendanceTimes.{$bobShift->id}.startTime", '12:30')
        ->set("attendanceTimes.{$bobShift->id}.endTime", '15:45')
        ->call('saveAttendance', $bobShift->id)
        ->assertHasNoErrors()
        ->assertSee('Attendance updated.')
        ->assertSee('Adjusted')
        ->assertSee('12:30–15:45')
        ->assertSee('Edited by Alice Admin')
        ->assertSee('Reset to planned');

    // Mark Carol as No-show
    $component
        ->call('markNoShow', $carolShift->id)
        ->assertHasNoErrors()
        ->assertSee('Marked as no-show.')
        ->assertSee('No-show');

    // Reset Carol back to planned
    $component
        ->call('resetAttendance', $carolShift->id)
        ->assertHasNoErrors()
        ->assertSee('Attendance reset to planned.')
        ->assertSet("attendanceTimes.{$carolShift->id}.startTime", '12:00')
        ->assertSet("attendanceTimes.{$carolShift->id}.endTime", '16:00');

    // Update slot planned times to 13:00–17:00: Carol's pre-filled inputs update, Bob's stay 12:30–15:45
    $component
        ->set('startTime', '13:00')
        ->set('endTime', '17:00')
        ->call('updateTimes')
        ->assertHasNoErrors()
        ->assertSet("attendanceTimes.{$carolShift->id}.startTime", '13:00')
        ->assertSet("attendanceTimes.{$carolShift->id}.endTime", '17:00')
        ->assertSet("attendanceTimes.{$bobShift->id}.startTime", '12:30')
        ->assertSet("attendanceTimes.{$bobShift->id}.endTime", '15:45');
});

it('forbids slot detail attendance actions for shifts belonging to another slot', function () {
    [$team, $season] = createAttendanceTeam();
    $slotA = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);
    $slotB = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 2,
    ]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create();
    $membership = Membership::factory()->for($volunteer)->for($team)->create();
    $shiftOnSlotB = Shift::factory()->confirmed()->for($slotB)->for($membership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->set("attendanceTimes.{$shiftOnSlotB->id}.startTime", '12:30')
        ->set("attendanceTimes.{$shiftOnSlotB->id}.endTime", '15:30')
        ->call('saveAttendance', $shiftOnSlotB->id)
        ->assertForbidden();

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->call('markNoShow', $shiftOnSlotB->id)
        ->assertForbidden();

    Livewire::test('pages::schedule.slot', ['slot' => $slotA])
        ->call('resetAttendance', $shiftOnSlotB->id)
        ->assertForbidden();
});
