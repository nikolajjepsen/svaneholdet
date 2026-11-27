<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

it('lets an admin assign past capacity after accepting the over-capacity warning', function () {
    $team = Team::factory()->create([
        'name' => 'Bar Team',
        'timezone' => 'Europe/Copenhagen',
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 1,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $firstVolunteer = User::factory()->create(['name' => 'Clara Confirmed', 'locale' => 'en']);
    $firstMembership = Membership::factory()->for($firstVolunteer)->for($team)->create();
    Shift::factory()->confirmed()->for($slot)->for($firstMembership)->create([
        'confirmed_at' => now()->subMinutes(10),
    ]);

    $secondVolunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $secondMembership = Membership::factory()->for($secondVolunteer)->for($team)->create();

    $this->actingAs($admin);

    visit('/schedule')
        ->assertSee('08:00–16:00')
        ->assertSee('1 / 1')
        ->click("@open-slot-{$slot->id}")
        ->assertPathIs("/schedule/slots/{$slot->id}")
        ->assertSee('Clara Confirmed')
        ->assertSee('1 / 1')
        ->select('assignMembershipId', (string) $secondMembership->id)
        ->click('@assign-member-submit')
        ->assertSee('will put it over capacity.')
        ->click('@accept-over-capacity-warning')
        ->assertSee('Member assigned to slot.')
        ->assertSee('2 / 1')
        ->assertSee('Bob Volunteer')
        ->assertSee('Over capacity')
        ->assertNoSmoke();

    $shift = Shift::query()
        ->where('slot_id', $slot->id)
        ->where('membership_id', $secondMembership->id)
        ->sole();

    expect($shift->status)->toBe(ShiftStatus::Confirmed);
    expect($shift->confirmed_at)->not->toBeNull();
});
