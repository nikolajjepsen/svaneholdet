<?php

use App\Enums\AttendanceStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;

it('chains day overview, slot attendance edits, hours overview history, and volunteer my shifts end to end', function () {
    $team = Team::factory()->create([
        'name' => 'Bar Team',
        'timezone' => 'Europe/Copenhagen',
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $bob = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $bobMembership = Membership::factory()->for($bob)->for($team)->create();

    $carol = User::factory()->create(['name' => 'Carol Volunteer', 'locale' => 'en']);
    $carolMembership = Membership::factory()->for($carol)->for($team)->create();

    $todayLocal = now()->toImmutable()->setTimezone('Europe/Copenhagen')->startOfDay();

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => $todayLocal->setTime(8, 0)->utc(),
        'ends_at' => $todayLocal->setTime(12, 0)->utc(),
        'capacity' => 3,
    ]);

    $bobShift = Shift::factory()->confirmed()->for($slot)->for($bobMembership)->create();
    $carolShift = Shift::factory()->confirmed()->for($slot)->for($carolMembership)->create();

    // 1. Admin views Day overview -> clicks into Slot -> adjusts Bob and marks Carol No-show -> checks Hours overview
    $this->actingAs($admin);

    visit('/day-overview')
        ->assertSee('Day overview')
        ->assertSee('08:00–12:00')
        ->assertSee('2 / 3')
        ->click("@open-slot-{$slot->id}")
        ->assertPathIs("/schedule/slots/{$slot->id}")
        ->assertSee('Bob Volunteer')
        ->assertSee('Carol Volunteer')
        ->fill("attendanceStartTime{$bobShift->id}", '08:30')
        ->fill("attendanceEndTime{$bobShift->id}", '11:45')
        ->click("@attendance-save-{$bobShift->id}")
        ->assertSee('Attendance updated.')
        ->assertSee('Adjusted · 08:30–11:45')
        ->click("@attendance-no-show-{$carolShift->id}")
        ->assertSee('Marked as no-show.')
        ->click('Hours overview')
        ->assertPathIs('/hours')
        ->assertSee('Bob Volunteer')
        ->assertSee('3 h 15 m')
        ->assertSee('Carol Volunteer')
        ->click("@toggle-member-{$bobMembership->id}")
        ->assertSee('08:00–12:00')
        ->assertSee('08:30–11:45')
        ->assertSee('Adjusted')
        ->assertSee('Edited by Alice Admin')
        ->click("@toggle-member-{$carolMembership->id}")
        ->assertSee('No-show')
        ->assertSee('0 h')
        ->assertNoSmoke();

    $bobShift->refresh();
    $carolShift->refresh();
    expect($bobShift->attendance_status)->toBe(AttendanceStatus::Adjusted);
    expect($carolShift->attendance_status)->toBe(AttendanceStatus::NoShow);

    // 2. Bob logs in and sees updated totals and Adjusted status on My shifts
    $this->actingAs($bob);

    visit('/my-shifts')
        ->assertSee('My shifts')
        ->assertSee('4 h')
        ->assertSee('3 h 15 m')
        ->assertSee('Adjusted')
        ->assertSee('Actual: 08:30–11:45 · 3 h 15 m')
        ->assertDontSee('Edited by Alice Admin')
        ->assertNoSmoke();

    // 3. Carol logs in and sees updated totals and No-show status on My shifts
    $this->actingAs($carol);

    visit('/my-shifts')
        ->assertSee('My shifts')
        ->assertSee('4 h')
        ->assertSee('0 h')
        ->assertSee('No-show')
        ->assertDontSee('Edited by Alice Admin')
        ->assertNoSmoke();
});
