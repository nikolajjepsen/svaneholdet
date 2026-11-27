<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;

beforeEach(function (): void {
    $this->team = Team::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->season = Season::factory()->for($this->team)->create();
    $this->team->update(['current_season_id' => $this->season->id]);
    $this->admin = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['locale' => 'en']))->create();
    $this->actingAs($this->admin->user);
});

it('creates an admin-only slot from the schedule', function () {
    $page = visit('/schedule')->click('@open-create-slot')
        ->click('@create-slot-admin-only')
        ->click('@create-slot-submit')
        ->assertSee('Slot created.');

    $slot = Slot::query()->sole();
    $page->assertSeeIn("@slot-card-{$slot->id}", 'Admin-only')->assertNoSmoke();
});

it('rolls out an admin-only slot, signs up, and frees its place when an assigned admin is demoted', function () {
    $otherAdmin = Membership::factory()->admin()->for($this->team)
        ->for(User::factory()->create(['name' => 'Astrid Andersen']))->create();
    $date = now()->setTimezone($this->team->timezone)->startOfMonth()->addMonth()->setDay(10)->toDateString();

    $page = visit('/schedule')->click('@open-rollout')
        ->click('@calendar-next-month')
        ->click("@calendar-day-{$date}")
        ->fill('@interval-0-capacity', '1')
        ->click('@interval-0-admin-only')
        ->assertSeeIn('[wire\:key^="preview-"]', 'Admin-only')
        ->click('@rollout-save')
        ->assertPathIs('/schedule');

    $slot = Slot::query()->sole();
    $series = SlotSeries::query()->sole();

    $page->assertSeeIn("@slot-card-{$slot->id}", 'Admin-only')
        ->click("@open-series-{$series->id}")
        ->assertPathIs("/schedule/series/{$series->id}")
        ->assertSee('Admin-only')
        ->navigate('/schedule')
        ->click("@sign-up-slot-{$slot->id}")
        ->assertSee('You are signed up for this slot.')
        ->click("@open-slot-{$slot->id}")
        ->assertSee('Admin-only')
        ->select('@assign-member-select', $otherAdmin->id)
        ->click('@assign-member-submit')
        ->assertSee('will put it over capacity')
        ->click('@accept-over-capacity-warning')
        ->assertSeeIn('@slot-capacity-badge', '2 / 1')
        ->navigate('/day-overview')
        ->assertSeeIn("@day-slot-{$slot->id}", 'Admin-only')
        ->assertSeeIn("@confirmed-count-slot-{$slot->id}", '2 / 1')
        ->navigate('/members')
        ->click("@demote-member-{$otherAdmin->id}")
        ->assertSee('Admin demoted to volunteer.')
        ->navigate('/day-overview')
        ->assertSeeIn("@confirmed-count-slot-{$slot->id}", '1 / 1')
        ->assertDontSee('Astrid')
        ->assertNoSmoke();
});
