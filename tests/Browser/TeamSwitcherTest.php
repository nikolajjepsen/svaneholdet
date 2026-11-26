<?php

use App\Mail\LoginLinkMail;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('lets a user on two teams switch team from the sidebar and reopens the last chosen team on login', function () {
    Mail::fake();
    $user = User::factory()->create(['locale' => 'en']);
    $teamA = Team::factory()->create(['name' => 'Team Alpha']);
    $teamB = Team::factory()->create(['name' => 'Team Beta']);

    Membership::factory()->admin()->for($user)->for($teamA)->create(['created_at' => now()->subDays(2)]);
    Membership::factory()->for($user)->for($teamB)->create(['created_at' => now()->subDay()]);

    $this->actingAs($user);

    $page = visit('/dashboard')
        ->assertPathIs('/dashboard')
        ->assertSeeIn('[data-flux-main]', 'Team Alpha')
        ->assertSee('Invite a volunteer')
        ->click('@sidebar-team-switcher')
        ->click("button[data-test=\"switch-team-{$teamB->id}\"]:visible")
        ->assertPathIs('/dashboard')
        ->assertSeeIn('[data-flux-main]', 'Team Beta')
        ->assertDontSee('Invite a volunteer');

    $page->click('@sidebar-menu-button')
        ->click('button[data-test="logout-button"]:visible')
        ->assertPathIs('/')
        ->navigate('/login')
        ->fill('email', $user->email)
        ->click('@send-login-link')
        ->assertSee('Check your email');

    $url = Mail::sent(LoginLinkMail::class)->sole()->url;

    $page->navigate(parse_url($url, PHP_URL_PATH))
        ->click('@confirm-login')
        ->assertPathIs('/dashboard')
        ->assertSeeIn('[data-flux-main]', 'Team Beta')
        ->assertNoSmoke();
});

it('shows the team name as plain text without a switcher for a user on one team', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $team = Team::factory()->create(['name' => 'Solo Team']);
    Team::factory()->create(['name' => 'Other Team']);

    Membership::factory()->for($user)->for($team)->create();
    $this->actingAs($user);

    visit('/dashboard')
        ->assertPathIs('/dashboard')
        ->assertSeeIn('@sidebar-team-name', 'Solo Team')
        ->assertMissing('@sidebar-team-switcher')
        ->assertDontSee('Other Team')
        ->assertNoSmoke();
});
