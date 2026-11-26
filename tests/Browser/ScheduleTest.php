<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;

it('lets an admin create a slot and a volunteer sign up at phone width', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    Membership::factory()->for($volunteer)->for($team)->create();

    $this->actingAs($admin);

    visit('/dashboard')
        ->click('Schedule')
        ->assertPathIs('/schedule')
        ->assertSee('Smukfest 2027')
        ->click('@open-create-slot')
        ->fill('date', '2027-08-04')
        ->fill('startTime', '08:00')
        ->fill('endTime', '16:00')
        ->fill('capacity', '2')
        ->click('@create-slot-submit')
        ->assertSee('Slot created.')
        ->assertSee('08:00–16:00')
        ->assertSee('0 / 2')
        ->assertNoSmoke();

    $slot = Slot::query()->where('season_id', $season->id)->sole();

    $this->actingAs($volunteer);

    visit('/schedule')
        ->on()->mobile()
        ->assertSee('08:00–16:00')
        ->assertSee('0 / 2')
        ->click("@sign-up-slot-{$slot->id}")
        ->assertSee('You are signed up for this slot.')
        ->assertSee('1 / 2')
        ->assertSee('Signed up')
        ->navigate('/my-shifts')
        ->assertPathIs('/my-shifts')
        ->assertSee('08:00–16:00')
        ->assertSee('8 h')
        ->assertSee('Confirmed')
        ->assertNoSmoke();
});
