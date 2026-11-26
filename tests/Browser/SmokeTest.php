<?php

use App\Mail\LoginLinkMail;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('renders the welcome page without browser errors', function () {
    visit('/')->assertNoSmoke();
});

it('lets a member log in through an emailed link and reach the dashboard', function () {
    Mail::fake();
    $user = User::factory()->has(Membership::factory()->count(2))->create();

    $page = visit('/')
        ->click('Log in')
        ->assertPathIs('/login')
        ->fill('email', $user->email)
        ->click('@send-login-link')
        ->assertSee('Check your email');

    $url = Mail::sent(LoginLinkMail::class)->sole()->url;

    $page->navigate(parse_url($url, PHP_URL_PATH))
        ->assertSee('Log in')
        ->click('@confirm-login')
        ->assertPathIs('/dashboard')
        ->assertNoSmoke();
});

it('lets a member request a fresh link from an expired link', function () {
    Mail::fake();
    $user = User::factory()->has(Membership::factory()->count(2))->create();
    $page = visit('/login')
        ->fill('email', $user->email)
        ->click('@send-login-link')
        ->assertSee('Check your email');
    $url = Mail::sent(LoginLinkMail::class)->sole()->url;
    $this->travel(16)->minutes();

    $page->navigate(parse_url($url, PHP_URL_PATH))
        ->assertSee('This login link is no longer available.')
        ->assertValue('email', $user->email)
        ->click('@send-login-link')
        ->assertSee('Check your email')
        ->assertNoSmoke();

    Mail::assertSentCount(2);
});

it('lets an authenticated user log out from the dashboard', function () {
    $this->actingAs(User::factory()->has(Membership::factory()->count(2))->create());

    visit('/dashboard')
        ->assertPathIs('/dashboard')
        ->click('@sidebar-menu-button')
        ->click('button[data-test="logout-button"]:visible')
        ->assertPathIs('/')
        ->assertNoSmoke();
});
