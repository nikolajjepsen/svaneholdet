<?php

use App\Mail\InvitationMail;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('lets an admin send an invitation and the invitee accept it from their email', function () {
    config(['app.locale' => 'da']);
    Mail::fake();
    $admin = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->admin()->for($admin)->create();
    Membership::factory()->for($admin)->create();
    $team = $membership->team()->sole();
    $this->actingAs($admin);

    $page = visit('/dashboard')
        ->click('Invite a volunteer')
        ->assertPathIs('/invitations/create')
        ->fill('name', 'Karla Volunteer')
        ->fill('email', 'karla@example.com')
        ->click('@send-invitation')
        ->assertSee('Invitation sent.');

    $url = Mail::sent(InvitationMail::class)->sole()->url;

    $page->click('@sidebar-menu-button')
        ->click('button[data-test="logout-button"]:visible')
        ->assertPathIs('/')
        ->navigate(parse_url($url, PHP_URL_PATH))
        ->assertSee($team->name)
        ->assertSee('Acceptér')
        ->click('@accept-invitation')
        ->assertPathIs('/dashboard')
        ->assertSee($team->name)
        ->assertSee('Karla Volunteer')
        ->assertDontSee('Invitér en frivillig')
        ->navigate(parse_url($url, PHP_URL_PATH))
        ->assertPathIs('/login')
        ->assertNoSmoke();
});

it('shows the reason an unavailable invitation cannot be accepted', function (bool $withdrawn, string $message) {
    Mail::fake();
    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->create();
    Membership::factory()->for($admin)->create();
    $this->actingAs($admin);

    $page = visit('/invitations/create')
        ->fill('name', 'Karla Volunteer')
        ->fill('email', 'karla@example.com')
        ->click('@send-invitation')
        ->assertSee('Invitation sent.');

    if ($withdrawn) {
        DB::table('invitations')->where('email', 'karla@example.com')->update(['withdrawn_at' => now()]);
    } else {
        $this->travel(14)->days();
    }

    $url = Mail::sent(InvitationMail::class)->sole()->url;

    $page->click('@sidebar-menu-button')
        ->click('button[data-test="logout-button"]:visible')
        ->assertPathIs('/')
        ->navigate(parse_url($url, PHP_URL_PATH))
        ->assertSee($message)
        ->assertDontSee('Accept')
        ->assertNoSmoke();
})->with([
    'expired' => [false, 'This invitation has expired. Ask a team admin to resend it.'],
    'withdrawn' => [true, 'This invitation was withdrawn.'],
]);
