<?php

use App\Mail\LoginLinkMail;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('emails a fifteen-minute login link to a member even if another membership was removed', function () {
    Mail::fake();
    $this->freezeTime();
    $user = User::factory()->create(['email' => 'member@example.com', 'locale' => 'en']);
    $teams = Team::factory()->count(2)->create();
    Membership::factory()->for($user)->for($teams[0])->create();
    Membership::factory()->removed()->for($user)->for($teams[1])->create();

    $this->get('/login')->assertOk()->assertSee('Email address');
    $this->post('/login', ['email' => 'MEMBER@example.com'])->assertRedirect('/login');
    $this->get('/login')->assertSee('Check your email');

    Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail): bool => $mail->hasTo($user->email));
    $mail = Mail::sent(LoginLinkMail::class)->sole();
    $mail->assertHasSubject('Your login link')->assertSeeInHtml('Open login link');
    $token = basename($mail->url);
    $this->assertDatabaseHas('login_links', [
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addMinutes(15)->toDateTimeString(),
        'used_at' => null,
    ]);
});

it('uses the login link only when the member presses the button and offers a new link after use', function () {
    Mail::fake();
    $user = User::factory()->has(Membership::factory()->count(2))->create();
    $this->post('/login', ['email' => $user->email])->assertRedirect('/login');
    $url = Mail::sent(LoginLinkMail::class)->sole()->url;

    $this->get($url)->assertOk()
        ->assertSee('data-test="confirm-login"', false)
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get($url)->assertOk()->assertSee('data-test="confirm-login"', false);
    expect(session()->previousUrl())->not->toBe($url);
    $this->assertGuest();

    $this->post($url)->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);

    $this->post('/logout')->assertRedirect('/');
    $this->get($url)->assertOk()->assertSee('This login link is no longer available.')
        ->assertSee('value="'.$user->email.'"', false);
    $this->post($url)->assertOk()->assertSee('Send login link');
    $this->assertGuest();
});

it('offers a prefilled form when the link expires between opening and pressing the button', function () {
    Mail::fake();
    $user = User::factory()->has(Membership::factory()->count(2))->create();
    $this->post('/login', ['email' => $user->email]);
    $url = Mail::sent(LoginLinkMail::class)->sole()->url;
    $this->travel(14)->minutes();
    $this->get($url)->assertOk()->assertSee('data-test="confirm-login"', false);

    $this->travel(2)->minutes();
    $this->post($url)->assertOk()->assertSee('This login link is no longer available.')
        ->assertSee('value="'.$user->email.'"', false);
    $this->get($url)->assertOk()->assertSee('Send login link');

    $this->assertGuest();
});

it('gives the same response for a member, an unknown address and a user with only removed memberships', function () {
    Mail::fake();
    $teams = Team::factory()->count(2)->create();
    $member = User::factory()->create();
    $removed = User::factory()->create();
    Membership::factory()->for($member)->for($teams[0])->create();
    Membership::factory()->removed()->for($removed)->for($teams[0])->create();
    Membership::factory()->removed()->for($removed)->for($teams[1])->create();

    foreach ([$member->email, 'unknown@example.com', $removed->email, 'invalid'] as $email) {
        $this->post('/login', ['email' => $email])->assertRedirect('/login')
            ->assertSessionHas('status', 'Check your email. If you have access, we sent you a login link.');
    }

    Mail::assertSentCount(1);
    Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail): bool => $mail->hasTo($member->email));
});

it('refuses to consume a link after the user loses their last active membership', function () {
    Mail::fake();
    $user = User::factory()->create();
    $teams = Team::factory()->count(2)->create();
    $membership = Membership::factory()->for($user)->for($teams[0])->create();
    Membership::factory()->removed()->for($user)->for($teams[1])->create();
    $this->post('/login', ['email' => $user->email]);
    $url = Mail::sent(LoginLinkMail::class)->sole()->url;
    $membership->update(['status' => 'removed']);

    $this->post($url)->assertOk()->assertSee('Send login link');

    $this->assertGuest();
});

it('sends at most three links per normalized email in ten minutes', function () {
    Mail::fake();
    $this->freezeTime();
    $teams = Team::factory()->count(2)->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);
    Membership::factory()->for($member)->for($teams[0])->create();
    Membership::factory()->for($other)->for($teams[1])->create();

    foreach (['member@example.com', 'MEMBER@example.com', 'member@example.com', 'MEMBER@EXAMPLE.COM'] as $email) {
        $this->post('/login', ['email' => $email])->assertSessionHas('status', 'Check your email. If you have access, we sent you a login link.');
    }
    expect(Mail::sent(LoginLinkMail::class, fn (LoginLinkMail $mail): bool => $mail->hasTo($member->email)))->toHaveCount(3);
    $this->post('/login', ['email' => $other->email]);
    Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail): bool => $mail->hasTo($other->email));

    $this->travel(10)->minutes();
    $this->post('/login', ['email' => $member->email]);

    expect(Mail::sent(LoginLinkMail::class, fn (LoginLinkMail $mail): bool => $mail->hasTo($member->email)))->toHaveCount(4);
});

it('renews the ninety-day session when used and expires it after ninety idle days', function () {
    config(['session.driver' => 'database']);
    Mail::fake();
    $this->freezeTime();
    $user = User::factory()->has(Membership::factory()->count(2))->create();
    $this->post('/login', ['email' => $user->email]);
    $url = Mail::sent(LoginLinkMail::class)->sole()->url;
    $cookieName = config('session.cookie');

    $login = $this->post($url)->assertRedirect('/dashboard');
    $sessionCookie = $login->getCookie($cookieName);
    expect($sessionCookie->getExpiresTime())->toBe(now()->addDays(90)->timestamp);

    $this->travel(89)->days();
    auth()->forgetGuards();
    session()->flush();
    $dashboard = $this->withCookie($cookieName, $sessionCookie->getValue())->get('/dashboard')->assertOk();
    $renewedCookie = $dashboard->getCookie($cookieName);
    expect($renewedCookie->getExpiresTime())->toBe(now()->addDays(90)->timestamp);

    $this->travel(2)->days();
    auth()->forgetGuards();
    session()->flush();
    $afterRenewal = $this->withCookie($cookieName, $renewedCookie->getValue())->get('/dashboard')->assertOk();

    $this->travel(91)->days();
    auth()->forgetGuards();
    session()->flush();
    $this->withCookie($cookieName, $afterRenewal->getCookie($cookieName)->getValue())->get('/dashboard')->assertRedirect('/login');
});

it('redirects an authenticated user away from the login page to the dashboard', function () {
    $user = User::factory()->has(Membership::factory())->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect('/dashboard');
});
