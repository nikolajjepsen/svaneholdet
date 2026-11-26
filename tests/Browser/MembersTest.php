<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('lets an admin manage members and pending invitations from the member list', function () {
    Mail::fake();

    $team = Team::factory()->create(['name' => 'Alpha Team']);
    $otherTeam = Team::factory()->create(['name' => 'Beta Team']);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();
    Membership::factory()->for($admin)->for($otherTeam)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'locale' => 'en']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($team)->create();

    $this->actingAs($admin);

    $page = visit('/dashboard')
        ->click('Members')
        ->assertPathIs('/members')
        ->assertSee('Alpha Team')
        ->assertSee('Alice Admin')
        ->assertSee('Bob Volunteer')
        ->click("@promote-member-{$volunteerMembership->id}")
        ->assertSee('Member promoted to admin.');

    expect($volunteerMembership->fresh()->role)->toBe(MembershipRole::Admin);

    $page->click("@demote-member-{$volunteerMembership->id}")
        ->assertSee('Admin demoted to volunteer.');

    expect($volunteerMembership->fresh()->role)->toBe(MembershipRole::Volunteer);

    $page->click("@remove-member-{$volunteerMembership->id}")
        ->assertSee('Member removed from the team.')
        ->assertDontSee('Bob Volunteer');

    expect($volunteerMembership->fresh()->status)->toBe(MembershipStatus::Removed);

    $page->click('Invite a volunteer')
        ->assertPathIs('/invitations/create')
        ->fill('name', 'Eva Pending')
        ->fill('email', 'eva@example.com')
        ->click('@send-invitation')
        ->assertSee('Invitation sent.')
        ->click('Members')
        ->assertPathIs('/members')
        ->assertSee('Eva Pending');

    $invitation = Invitation::query()->where('email', 'eva@example.com')->sole();
    $firstUrl = Mail::sent(InvitationMail::class)->last()->url;

    $page->click("@resend-invitation-{$invitation->id}")
        ->assertSee('Invitation resent.');

    Mail::assertSentCount(2);
    $resentUrl = Mail::sent(InvitationMail::class)->last()->url;
    expect($resentUrl)->not->toBe($firstUrl);

    $page->click("@withdraw-invitation-{$invitation->id}")
        ->assertSee('Invitation withdrawn.')
        ->assertDontSee('Eva Pending');

    $page->click('@sidebar-menu-button')
        ->click('button[data-test="logout-button"]:visible')
        ->assertPathIs('/')
        ->navigate(parse_url($resentUrl, PHP_URL_PATH))
        ->assertSee('This invitation was withdrawn.')
        ->assertNoSmoke();
});
