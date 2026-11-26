<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->team = Team::factory()->create(['name' => 'Alpha Team']);
    $this->otherTeam = Team::factory()->create(['name' => 'Beta Team']);

    $this->admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en']);
    $this->adminMembership = Membership::factory()->admin()->for($this->admin)->for($this->team)->create();
    $this->otherMembership = Membership::factory()->for($this->admin)->for($this->otherTeam)->create();
});

it('shows active members with their roles and pending invitations for the current team only', function () {
    $volunteer = User::factory()->create(['name' => 'Bob Volunteer']);
    Membership::factory()->for($volunteer)->for($this->team)->create();

    $removedUser = User::factory()->create(['name' => 'Charlie Removed']);
    Membership::factory()->removed()->for($removedUser)->for($this->team)->create();

    $otherTeamMember = User::factory()->create(['name' => 'Dana OtherTeam']);
    Membership::factory()->admin()->for($otherTeamMember)->for($this->otherTeam)->create();

    Invitation::factory()->for($this->team)->create([
        'invited_by' => $this->admin->id,
        'name' => 'Eva Pending',
        'email' => 'eva@example.com',
    ]);
    Invitation::factory()->for($this->team)->create([
        'invited_by' => $this->admin->id,
        'name' => 'Frank Expired',
        'email' => 'frank@example.com',
        'expires_at' => now()->subMinute(),
    ]);
    Invitation::factory()->for($this->team)->create([
        'invited_by' => $this->admin->id,
        'name' => 'Grace Withdrawn',
        'email' => 'grace@example.com',
        'withdrawn_at' => now()->subMinute(),
    ]);
    Invitation::factory()->for($this->team)->create([
        'invited_by' => $this->admin->id,
        'name' => 'Henry Redeemed',
        'email' => 'henry@example.com',
        'redeemed_at' => now()->subMinute(),
    ]);
    Invitation::factory()->for($this->otherTeam)->create([
        'invited_by' => $otherTeamMember->id,
        'name' => 'Ivy OtherInvite',
        'email' => 'ivy@example.com',
    ]);

    $this->actingAs($this->admin)
        ->get('/members')
        ->assertOk()
        ->assertSee('Alpha Team')
        ->assertSee('Alice Admin')
        ->assertSee('Admin')
        ->assertSee('Bob Volunteer')
        ->assertSee('Volunteer')
        ->assertSee('Eva Pending')
        ->assertSee('eva@example.com')
        ->assertSee('Pending')
        ->assertDontSee('Charlie Removed')
        ->assertDontSee('Dana OtherTeam')
        ->assertDontSee('Frank Expired')
        ->assertDontSee('Grace Withdrawn')
        ->assertDontSee('Henry Redeemed')
        ->assertDontSee('Ivy OtherInvite');
});

it('refuses a volunteer of the current team even when they administer another team', function () {
    $this->adminMembership->update(['role' => MembershipRole::Volunteer]);
    $this->otherMembership->update(['role' => MembershipRole::Admin]);

    $this->actingAs($this->admin)->get('/members')->assertForbidden();
    Livewire::test('pages::members.index')->assertForbidden();
});

it('resends a pending invitation with a fresh 14-day link and invalidates the old link', function () {
    $this->freezeSecond();
    $this->actingAs($this->admin);

    Livewire::test('pages::invitations.create')
        ->set('name', 'Eva Pending')
        ->set('email', 'eva@example.com')
        ->call('invite')
        ->assertHasNoErrors();

    $firstMail = Mail::sent(InvitationMail::class)->last();
    $oldPath = parse_url($firstMail->url, PHP_URL_PATH);
    $invitation = Invitation::query()->where('email', 'eva@example.com')->sole();

    $this->travel(5)->days();

    Livewire::test('pages::members.index')
        ->call('resend', $invitation->id)
        ->assertHasNoErrors();

    Mail::assertSentCount(2);
    $secondMail = Mail::sent(InvitationMail::class)->last();
    $secondMail->assertTo('eva@example.com')
        ->assertHasSubject('Invitation til Alpha Team');

    $newPath = parse_url($secondMail->url, PHP_URL_PATH);
    expect($newPath)->not->toBe($oldPath)
        ->and($invitation->fresh()->expires_at->toDateTimeString())->toBe(now()->addDays(14)->toDateTimeString());

    $this->post('/logout');
    Auth::forgetGuards();

    $this->get($oldPath)->assertOk()->assertSee('This invitation link is unavailable.');
    $this->post($oldPath)->assertOk()->assertSee('This invitation link is unavailable.');
    $this->assertGuest();

    $this->get($newPath)->assertOk()->assertSee('Accept');
    $this->post($newPath)->assertRedirect('/dashboard');
    $this->assertAuthenticated();
});

it('refuses resending an invitation belonging to another team even when administering both teams', function () {
    $this->otherMembership->update(['role' => MembershipRole::Admin]);
    $otherInvitation = Invitation::factory()->for($this->otherTeam)->create([
        'invited_by' => $this->admin->id,
        'email' => 'other@example.com',
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('resend', $otherInvitation->id)
        ->assertForbidden();

    Mail::assertNothingSent();
});

it('withdraws a pending invitation so its link shows the withdrawn page', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::invitations.create')
        ->set('name', 'Eva Pending')
        ->set('email', 'eva@example.com')
        ->call('invite')
        ->assertHasNoErrors();

    $path = parse_url(Mail::sent(InvitationMail::class)->last()->url, PHP_URL_PATH);
    $invitation = Invitation::query()->where('email', 'eva@example.com')->sole();

    Livewire::test('pages::members.index')
        ->call('withdraw', $invitation->id)
        ->assertHasNoErrors()
        ->assertDontSee('Eva Pending');

    expect($invitation->fresh()->withdrawn_at)->not->toBeNull();

    $this->post('/logout');
    Auth::forgetGuards();

    $this->get($path)->assertOk()->assertSee('This invitation was withdrawn.');
    $this->post($path)->assertOk()->assertSee('This invitation was withdrawn.');
    $this->assertGuest();
});

it('refuses withdrawing an invitation belonging to another team', function () {
    $this->otherMembership->update(['role' => MembershipRole::Admin]);
    $otherInvitation = Invitation::factory()->for($this->otherTeam)->create([
        'invited_by' => $this->admin->id,
        'email' => 'other@example.com',
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('withdraw', $otherInvitation->id)
        ->assertForbidden();

    expect($otherInvitation->fresh()->withdrawn_at)->toBeNull();
});

it('promotes a volunteer to admin and demotes an admin to volunteer', function () {
    $volunteer = User::factory()->create(['name' => 'Bob Volunteer']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($this->team)->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('promote', $volunteerMembership->id)
        ->assertHasNoErrors();

    expect($volunteerMembership->fresh()->role)->toBe(MembershipRole::Admin);

    Livewire::test('pages::members.index')
        ->call('demote', $volunteerMembership->id)
        ->assertHasNoErrors();

    expect($volunteerMembership->fresh()->role)->toBe(MembershipRole::Volunteer);
});

it('lets an admin demote themselves while another admin remains', function () {
    $secondAdmin = User::factory()->create(['name' => 'Carol Admin']);
    Membership::factory()->admin()->for($secondAdmin)->for($this->team)->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('demote', $this->adminMembership->id)
        ->assertHasNoErrors()
        ->assertRedirect('/dashboard');

    expect($this->adminMembership->fresh()->role)->toBe(MembershipRole::Volunteer);
    $this->get('/members')->assertForbidden();
});

it('refuses demoting the last admin of a team', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('demote', $this->adminMembership->id)
        ->assertHasErrors('member')
        ->assertSee('The team must have at least one admin.');

    expect($this->adminMembership->fresh()->role)->toBe(MembershipRole::Admin);
});

it('refuses promoting or demoting memberships belonging to another team or after losing admin rights', function () {
    $this->otherMembership->update(['role' => MembershipRole::Admin]);
    $otherVolunteer = User::factory()->create();
    $otherTeamMembership = Membership::factory()->for($otherVolunteer)->for($this->otherTeam)->create();

    $volunteer = User::factory()->create();
    $teamVolunteerMembership = Membership::factory()->for($volunteer)->for($this->team)->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('promote', $otherTeamMembership->id)
        ->assertForbidden();

    Livewire::test('pages::members.index')
        ->call('demote', $this->otherMembership->id)
        ->assertForbidden();

    $component = Livewire::test('pages::members.index');
    $this->adminMembership->update(['role' => MembershipRole::Volunteer]);
    $component->call('promote', $teamVolunteerMembership->id)->assertForbidden();

    expect($otherTeamMembership->fresh()->role)->toBe(MembershipRole::Volunteer)
        ->and($this->otherMembership->fresh()->role)->toBe(MembershipRole::Admin)
        ->and($teamVolunteerMembership->fresh()->role)->toBe(MembershipRole::Volunteer);
});

it('removes a member while keeping their membership history', function () {
    $volunteer = User::factory()->create(['name' => 'Bob Volunteer']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($this->team)->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('remove', $volunteerMembership->id)
        ->assertHasNoErrors()
        ->assertDontSee('Bob Volunteer');

    $this->assertDatabaseHas('memberships', [
        'id' => $volunteerMembership->id,
        'user_id' => $volunteer->id,
        'team_id' => $this->team->id,
        'status' => MembershipStatus::Removed,
    ]);
});

it('refuses removing the last admin of a team', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('remove', $this->adminMembership->id)
        ->assertHasErrors('member')
        ->assertSee('The team must have at least one admin.');

    expect($this->adminMembership->fresh()->status)->toBe(MembershipStatus::Active);
});

it('lets an admin remove themselves while another admin remains and falls back to another active team', function () {
    $secondAdmin = User::factory()->create(['name' => 'Carol Admin']);
    Membership::factory()->admin()->for($secondAdmin)->for($this->team)->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('remove', $this->adminMembership->id)
        ->assertHasNoErrors()
        ->assertRedirect('/dashboard');

    expect($this->adminMembership->fresh()->status)->toBe(MembershipStatus::Removed);
    $this->assertAuthenticatedAs($this->admin);
    $this->get('/dashboard')->assertOk()->assertSee('Beta Team')->assertDontSee('Alpha Team');
});

it('logs out an admin who removes their last active membership', function () {
    $this->otherMembership->update(['status' => MembershipStatus::Removed]);
    $secondAdmin = User::factory()->create(['name' => 'Carol Admin']);
    Membership::factory()->admin()->for($secondAdmin)->for($this->team)->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('remove', $this->adminMembership->id)
        ->assertHasNoErrors()
        ->assertRedirect('/login');

    expect($this->adminMembership->fresh()->status)->toBe(MembershipStatus::Removed);
    $this->assertGuest();
});

it('refuses a removed member on their next request to that team and logs out a user with no active teams left', function () {
    $secondAdmin = User::factory()->create(['name' => 'Carol Admin', 'locale' => 'en']);
    $secondAdminMembership = Membership::factory()->admin()->for($secondAdmin)->for($this->team)->create();
    $secondAdminOther = Membership::factory()->for($secondAdmin)->for($this->otherTeam)->create();

    $this->actingAs($secondAdmin);
    $openPage = Livewire::test('pages::members.index');

    $this->actingAs($this->admin);
    Livewire::test('pages::members.index')->call('remove', $secondAdminMembership->id)->assertHasNoErrors();

    $this->actingAs($secondAdmin);
    $openPage->call('remove', $this->adminMembership->id)->assertForbidden();
    $this->get('/members')->assertForbidden();
    $this->get('/dashboard')->assertOk()->assertSee('Beta Team')->assertDontSee('Alpha Team');

    $secondAdminOther->update(['status' => MembershipStatus::Removed]);
    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('refuses removing a membership belonging to another team', function () {
    $this->otherMembership->update(['role' => MembershipRole::Admin]);
    $otherVolunteer = User::factory()->create();
    $otherTeamMembership = Membership::factory()->for($otherVolunteer)->for($this->otherTeam)->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('remove', $otherTeamMembership->id)
        ->assertForbidden();

    expect($otherTeamMembership->fresh()->status)->toBe(MembershipStatus::Active);
});

it('renders the member list in Danish for Danish users', function () {
    $this->admin->update(['locale' => 'da']);
    $volunteer = User::factory()->create(['name' => 'Bob Volunteer']);
    Membership::factory()->for($volunteer)->for($this->team)->create();
    Invitation::factory()->for($this->team)->create([
        'invited_by' => $this->admin->id,
        'name' => 'Eva Pending',
        'email' => 'eva@example.com',
    ]);

    $this->actingAs($this->admin)
        ->get('/members')
        ->assertOk()
        ->assertSee('Medlemmer')
        ->assertSee('Administrator')
        ->assertSee('Frivillig')
        ->assertSee('Afventer')
        ->assertSee('Gør til administrator')
        ->assertSee('Fjern')
        ->assertSee('Send igen')
        ->assertSee('Træk tilbage');
});
