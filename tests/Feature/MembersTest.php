<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\ShiftStatus;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
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

it('shows active members with their roles and unresolved invitations for the current team only', function () {
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
        ->assertSee('Frank Expired')
        ->assertSee('frank@example.com')
        ->assertSee('Expired')
        ->assertDontSee('Charlie Removed')
        ->assertDontSee('Dana OtherTeam')
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

it('resends an expired invitation with a fresh 14-day link while keeping the old link unusable', function () {
    $this->freezeSecond();
    $this->actingAs($this->admin);

    Livewire::test('pages::invitations.create')
        ->set('name', 'Frank Expired')
        ->set('email', 'frank@example.com')
        ->call('invite')
        ->assertHasNoErrors();

    $firstMail = Mail::sent(InvitationMail::class)->last();
    $oldPath = parse_url($firstMail->url, PHP_URL_PATH);
    $invitation = Invitation::query()->where('email', 'frank@example.com')->sole();

    $this->travel(15)->days();

    Livewire::test('pages::members.index')
        ->assertSee('Frank Expired')
        ->assertSee('Expired')
        ->call('resend', $invitation->id)
        ->assertHasNoErrors()
        ->assertSee('Pending');

    Mail::assertSentCount(2);
    $secondMail = Mail::sent(InvitationMail::class)->last();
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

it('withdraws an expired invitation so it leaves the list and shows the withdrawn page', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::invitations.create')
        ->set('name', 'Frank Expired')
        ->set('email', 'frank@example.com')
        ->call('invite')
        ->assertHasNoErrors();

    $path = parse_url(Mail::sent(InvitationMail::class)->last()->url, PHP_URL_PATH);
    $invitation = Invitation::query()->where('email', 'frank@example.com')->sole();

    $this->travel(15)->days();

    Livewire::test('pages::members.index')
        ->assertSee('Frank Expired')
        ->assertSee('Expired')
        ->call('withdraw', $invitation->id)
        ->assertHasNoErrors()
        ->assertDontSee('Frank Expired');

    expect($invitation->fresh()->withdrawn_at)->not->toBeNull();

    $this->post('/logout');
    Auth::forgetGuards();

    $this->get($path)->assertOk()->assertSee('This invitation was withdrawn.');
    $this->post($path)->assertOk()->assertSee('This invitation was withdrawn.');
    $this->assertGuest();
});

it('refuses resending or withdrawing an already redeemed or withdrawn invitation', function () {
    $redeemed = Invitation::factory()->for($this->team)->create([
        'invited_by' => $this->admin->id,
        'email' => 'redeemed@example.com',
        'redeemed_at' => now()->subMinute(),
    ]);
    $withdrawn = Invitation::factory()->for($this->team)->create([
        'invited_by' => $this->admin->id,
        'email' => 'withdrawn@example.com',
        'withdrawn_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('resend', $redeemed->id)
        ->assertHasErrors('invitation')
        ->assertSee('This invitation can no longer be changed.')
        ->call('withdraw', $redeemed->id)
        ->assertHasErrors('invitation')
        ->call('resend', $withdrawn->id)
        ->assertHasErrors('invitation')
        ->call('withdraw', $withdrawn->id)
        ->assertHasErrors('invitation');

    Mail::assertNothingSent();
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
    Invitation::factory()->for($this->team)->create([
        'invited_by' => $this->admin->id,
        'name' => 'Frank Expired',
        'email' => 'frank@example.com',
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->admin)
        ->get('/members')
        ->assertOk()
        ->assertSee('Medlemmer')
        ->assertSee('Administrator')
        ->assertSee('Frivillig')
        ->assertSee('Afventer')
        ->assertSee('Udløbet')
        ->assertSee('Gør til administrator')
        ->assertSee('Fjern')
        ->assertSee('Send igen')
        ->assertSee('Træk tilbage');
});

it('cancels upcoming pending and confirmed shifts without emailing when removing a member while preserving started and past shifts', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen'));

    $season = Season::factory()->for($this->team)->create();
    $this->team->update(['current_season_id' => $season->id]);
    $volunteer = User::factory()->create(['name' => 'Bob Volunteer']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($this->team)->create();

    $upcomingConfirmedSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 14:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 18:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $upcomingPendingSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 12:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $upcomingRejectedSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 14:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 18:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $upcomingCancelledSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-07 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-07 12:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $startedNowSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 12:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $pastSlot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00:00', 'Europe/Copenhagen')->utc(),
    ]);

    $upcomingConfirmed = Shift::factory()->confirmed()->for($upcomingConfirmedSlot)->for($volunteerMembership)->create();
    $upcomingPending = Shift::factory()->pending()->for($upcomingPendingSlot)->for($volunteerMembership)->create();
    $upcomingRejected = Shift::factory()->rejected()->for($upcomingRejectedSlot)->for($volunteerMembership)->create();
    $upcomingCancelled = Shift::factory()->cancelled()->for($upcomingCancelledSlot)->for($volunteerMembership)->create();
    $startedConfirmed = Shift::factory()->confirmed()->for($startedNowSlot)->for($volunteerMembership)->create();
    $pastConfirmed = Shift::factory()->confirmed()->for($pastSlot)->for($volunteerMembership)->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::members.index')
        ->call('remove', $volunteerMembership->id)
        ->assertHasNoErrors();

    expect($volunteerMembership->fresh()->status)->toBe(MembershipStatus::Removed)
        ->and($upcomingConfirmed->fresh()->status)->toBe(ShiftStatus::Cancelled)
        ->and($upcomingPending->fresh()->status)->toBe(ShiftStatus::Cancelled)
        ->and($upcomingRejected->fresh()->status)->toBe(ShiftStatus::Rejected)
        ->and($upcomingCancelled->fresh()->status)->toBe(ShiftStatus::Cancelled)
        ->and($startedConfirmed->fresh()->status)->toBe(ShiftStatus::Confirmed)
        ->and($pastConfirmed->fresh()->status)->toBe(ShiftStatus::Confirmed);

    Mail::assertNothingSent();
});

it('frees up slot capacity immediately when a removed member has their upcoming shift cancelled', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 10:00:00', 'Europe/Copenhagen'));

    $season = Season::factory()->for($this->team)->create();
    $this->team->update(['current_season_id' => $season->id]);
    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 14:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 18:00:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 1,
    ]);

    $removedVolunteer = User::factory()->create(['name' => 'Bob Volunteer']);
    $removedMembership = Membership::factory()->for($removedVolunteer)->for($this->team)->create();
    Shift::factory()->confirmed()->for($slot)->for($removedMembership)->create();

    $replacement = User::factory()->create(['name' => 'Carol Replacement']);
    $replacementMembership = Membership::factory()->for($replacement)->for($this->team)->create();

    $this->actingAs($this->admin);
    Livewire::test('pages::members.index')
        ->call('remove', $removedMembership->id)
        ->assertHasNoErrors();

    $this->actingAs($replacement);
    Livewire::test('pages::schedule.index')
        ->call('signUp', $slot->id)
        ->assertHasNoErrors();

    expect(Shift::query()
        ->where('slot_id', $slot->id)
        ->where('membership_id', $replacementMembership->id)
        ->where('status', ShiftStatus::Confirmed)
        ->exists())->toBeTrue();
});

it('does not cancel a removed member\'s upcoming shifts on another team', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-05 10:00:00', 'Europe/Copenhagen'));

    $teamSeason = Season::factory()->for($this->team)->create();
    $this->team->update(['current_season_id' => $teamSeason->id]);
    $otherSeason = Season::factory()->for($this->otherTeam)->create();
    $this->otherTeam->update(['current_season_id' => $otherSeason->id]);

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer']);
    $teamMembership = Membership::factory()->for($volunteer)->for($this->team)->create();
    $otherTeamMembership = Membership::factory()->for($volunteer)->for($this->otherTeam)->create();

    $teamSlot = Slot::factory()->for($teamSeason)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 12:00:00', 'Europe/Copenhagen')->utc(),
    ]);
    $otherSlot = Slot::factory()->for($otherSeason)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 08:00:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 12:00:00', 'Europe/Copenhagen')->utc(),
    ]);

    $teamShift = Shift::factory()->confirmed()->for($teamSlot)->for($teamMembership)->create();
    $otherShift = Shift::factory()->confirmed()->for($otherSlot)->for($otherTeamMembership)->create();

    $this->actingAs($this->admin);
    Livewire::test('pages::members.index')
        ->call('remove', $teamMembership->id)
        ->assertHasNoErrors();

    expect($teamShift->fresh()->status)->toBe(ShiftStatus::Cancelled)
        ->and($otherShift->fresh()->status)->toBe(ShiftStatus::Confirmed);
});
