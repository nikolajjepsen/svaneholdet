<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->admin = User::factory()->create(['locale' => 'en']);
    $this->membership = Membership::factory()->admin()->for($this->admin)->create();
    $this->otherMembership = Membership::factory()->for($this->admin)->create();
});

function sendInvitation(string $email = 'invitee@example.com', string $name = 'Invited Volunteer'): InvitationMail
{
    Livewire::test('pages::invitations.create')
        ->set('name', $name)
        ->set('email', $email)
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSee('Invitation sent.');

    return Mail::sent(InvitationMail::class)->last();
}

it('sends a Danish invitation that a guest can accept once as a volunteer', function () {
    $this->freezeSecond();
    $this->actingAs($this->admin)->get('/invitations/create')->assertOk();
    $mail = sendInvitation('INVITEE@example.com');

    $mail->assertTo('invitee@example.com')
        ->assertHasSubject('Invitation til '.$this->membership->team()->sole()->name)
        ->assertSeeInHtml('Åbn invitation');

    $path = parse_url($mail->url, PHP_URL_PATH);
    $token = basename($path);
    $this->assertDatabaseHas('invitations', [
        'team_id' => $this->membership->team_id,
        'email' => 'invitee@example.com',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(14)->toDateTimeString(),
        'redeemed_at' => null,
    ]);

    $this->post('/logout')->assertRedirect('/');
    Auth::forgetGuards();
    $this->get($path)->assertOk()->assertSee('Accept')->assertHeader('Cache-Control', 'no-store, private');
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'invitee@example.com']);

    $this->post($path)->assertRedirect('/dashboard');
    $invitee = User::query()->where('email', 'invitee@example.com')->sole();
    $this->assertAuthenticatedAs($invitee);
    expect($invitee->name)->toBe('Invited Volunteer');

    $this->assertDatabaseHas('memberships', [
        'user_id' => $invitee->id,
        'team_id' => $this->membership->team_id,
        'status' => MembershipStatus::Active,
        'role' => MembershipRole::Volunteer,
    ]);
    $this->assertDatabaseMissing('memberships', ['user_id' => $invitee->id, 'team_id' => $this->otherMembership->team_id]);
    $this->get($path)->assertRedirect('/login');
    $this->post($path)->assertRedirect('/login');
});

it('reactivates the same removed membership as a volunteer and keeps the existing name and locale', function () {
    $invitee = User::factory()->create(['locale' => 'en']);
    $removed = Membership::factory()->admin()->removed()->for($invitee)->create(['team_id' => $this->membership->team_id]);
    $other = Membership::factory()->admin()->for($invitee)->create(['team_id' => $this->otherMembership->team_id]);

    $this->actingAs($this->admin);
    $mail = sendInvitation($invitee->email);
    $mail->assertHasSubject('Invitation to '.$this->membership->team()->sole()->name)
        ->assertSeeInHtml('Open invitation');

    $this->post('/logout');
    Auth::forgetGuards();
    $this->post(parse_url($mail->url, PHP_URL_PATH))->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($invitee);
    expect($invitee->fresh()->name)->toBe($invitee->name)
        ->and($removed->fresh()->status)->toBe(MembershipStatus::Active)
        ->and($removed->fresh()->role)->toBe(MembershipRole::Volunteer)
        ->and($other->fresh()->role)->toBe(MembershipRole::Admin);
});

it('refuses a volunteer of the current team even when they administer another team', function () {
    $this->membership->update(['role' => MembershipRole::Volunteer]);
    $this->otherMembership->update(['role' => MembershipRole::Admin]);

    $this->actingAs($this->admin)->get('/invitations/create')->assertForbidden();
    Livewire::test('pages::invitations.create')->assertForbidden();

    Mail::assertNothingSent();
});

it('refuses invitations from an open form after team permissions change', function (array $change) {
    $this->otherMembership->update(['role' => MembershipRole::Admin]);
    $this->actingAs($this->admin);
    $form = Livewire::test('pages::invitations.create')
        ->set('name', 'Invited Volunteer')
        ->set('email', 'invitee@example.com');

    $this->membership->update($change);
    $form->call('invite')->assertForbidden();

    Mail::assertNothingSent();
})->with([
    'demoted on the current team' => [['role' => MembershipRole::Volunteer]],
    'removed from the current team' => [['status' => MembershipStatus::Removed]],
]);

it('logs the member out when a Livewire update arrives after their last membership is removed', function () {
    $page = $this->actingAs($this->admin)->get('/invitations/create')->assertOk();
    expect(preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches))->toBe(1);

    $this->membership->update(['status' => MembershipStatus::Removed]);
    $this->otherMembership->update(['status' => MembershipStatus::Removed]);

    $this->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
            'updates' => ['name' => 'Invited Volunteer', 'email' => 'invitee@example.com'],
            'calls' => [['method' => 'invite', 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true'])->assertRedirect('/login');

    $this->assertGuest();
    Mail::assertNothingSent();
});

it('refuses an active member on the current team and a second pending invitation for the same email', function () {
    $this->actingAs($this->admin);
    sendInvitation();

    Livewire::test('pages::invitations.create')
        ->set('name', 'Volunteer')
        ->set('email', 'INVITEE@example.com')
        ->call('invite')
        ->assertHasErrors('email')
        ->assertSee('An invitation is already pending for this email address.')
        ->set('email', strtoupper($this->admin->email))
        ->call('invite')
        ->assertHasErrors('email')
        ->assertSee('This person is already a member of the team.');

    Mail::assertSentCount(1);
    $this->assertDatabaseCount('invitations', 1);

    $this->membership->update(['status' => MembershipStatus::Removed]);
    $this->otherMembership->update(['role' => MembershipRole::Admin]);
    sendInvitation();

    Mail::assertSentCount(2);
    $this->assertDatabaseCount('invitations', 2);
});

it('explains why an expired or withdrawn invitation cannot be accepted', function (bool $withdrawn, string $message) {
    $this->actingAs($this->admin);
    $path = parse_url(sendInvitation()->url, PHP_URL_PATH);

    $this->post('/logout');
    Auth::forgetGuards();

    if ($withdrawn) {
        DB::table('invitations')->where('email', 'invitee@example.com')->update(['withdrawn_at' => now()]);
    } else {
        $this->travel(14)->days();
    }

    $this->get($path)->assertOk()->assertSee($message);
    $this->post($path)->assertOk()->assertSee($message);
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'invitee@example.com']);
})->with([
    'expired' => [false, 'This invitation has expired. Ask a team admin to resend it.'],
    'withdrawn' => [true, 'This invitation was withdrawn.'],
]);

it('falls back to the next active team and logs the member out when no active teams remain', function () {
    $team = $this->membership->team()->sole();
    $otherTeam = $this->otherMembership->team()->sole();

    $this->actingAs($this->admin)->get('/dashboard')->assertSee($team->name);
    $this->membership->update(['status' => MembershipStatus::Removed]);
    $this->get('/dashboard')->assertSee($otherTeam->name)->assertDontSee($team->name);
    $this->get('/invitations/create')->assertForbidden();

    $this->otherMembership->update(['status' => MembershipStatus::Removed]);
    $this->get('/invitations/create')->assertRedirect('/login');
    $this->assertGuest();
});

it('keeps one pending invitation per team and email at the database boundary', function () {
    $this->actingAs($this->admin);
    sendInvitation();

    expect(fn () => DB::transaction(fn () => Invitation::factory()->create([
        'team_id' => $this->membership->team_id,
        'invited_by' => $this->admin->id,
        'email' => 'invitee@example.com',
    ])))->toThrow(UniqueConstraintViolationException::class, 'invitations_one_unresolved_per_recipient');
});

it('switches an existing member to the newly joined team when accepting an invitation to a second team', function () {
    $firstTeam = $this->otherMembership->team()->sole();
    $secondTeam = $this->membership->team()->sole();

    $invitee = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $firstTeam->id]);
    Membership::factory()->for($invitee)->for($firstTeam)->create(['created_at' => now()->subDays(2)]);

    $this->actingAs($this->admin);
    $mail = sendInvitation($invitee->email, 'Ignored Name');

    $this->post('/logout');
    Auth::forgetGuards();

    $this->actingAs($invitee)
        ->withSession(['current_team_id' => $firstTeam->id])
        ->post(parse_url($mail->url, PHP_URL_PATH))
        ->assertRedirect('/dashboard')
        ->assertSessionHas('current_team_id', $secondTeam->id);

    expect($invitee->refresh()->last_chosen_team_id)->toBe($secondTeam->id);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee($secondTeam->name);
});

it('issues a fresh invitation for the same address after the old invitation expires', function () {
    $this->actingAs($this->admin);
    $expiredPath = parse_url(sendInvitation()->url, PHP_URL_PATH);
    $this->travel(14)->days();

    $newPath = parse_url(sendInvitation()->url, PHP_URL_PATH);

    $this->assertDatabaseCount('invitations', 1);
    $this->get($expiredPath)->assertSee('This invitation link is unavailable.');
    $this->get($newPath)->assertSee('Accept');
    $this->post($newPath)->assertRedirect('/dashboard');
});
