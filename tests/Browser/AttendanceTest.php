<?php

use App\Enums\AttendanceStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

it('lets an admin adjust actual times, mark a no-show, and reset attendance on the slot participant list', function () {
    $team = Team::factory()->create([
        'name' => 'Bar Team',
        'timezone' => 'Europe/Copenhagen',
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

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

    visit("/schedule/slots/{$slot->id}")
        ->assertSee('Bob Volunteer')
        ->assertSee('Carol Volunteer')
        ->fill("attendanceStartTime{$bobShift->id}", '12:30')
        ->fill("attendanceEndTime{$bobShift->id}", '15:45')
        ->click("@attendance-save-{$bobShift->id}")
        ->assertSee('Attendance updated.')
        ->assertSee('Adjusted · 12:30–15:45')
        ->assertSee('Edited by Alice Admin')
        ->click("@attendance-no-show-{$carolShift->id}")
        ->assertSee('Marked as no-show.')
        ->assertSee('No-show')
        ->click("@attendance-reset-{$carolShift->id}")
        ->assertSee('Attendance reset to planned.')
        ->assertNoSmoke();

    $bobShift->refresh();
    $carolShift->refresh();

    expect($bobShift->attendance_status)->toBe(AttendanceStatus::Adjusted);
    expect($bobShift->actual_starts_at?->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('12:30');
    expect($bobShift->actual_ends_at?->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('15:45');
    expect($bobShift->attendance_recorded_by)->toBe($admin->id);

    expect($carolShift->attendance_status)->toBe(AttendanceStatus::Planned);
    expect($carolShift->attendance_recorded_by)->toBeNull();
});
