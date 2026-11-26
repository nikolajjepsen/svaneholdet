<?php

use App\Models\User;

it('renders the welcome page without browser errors', function () {
    visit('/')->assertNoSmoke();
});

it('lets a user log in and reach the dashboard', function () {
    $user = User::factory()->create();

    visit('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->assertPathIs('/dashboard')
        ->assertNoSmoke();
})->skip('Issue #2 will restore this smoke test using a login link.');

it('lets an authenticated user log out from the dashboard', function () {
    $this->actingAs(User::factory()->create());

    visit('/dashboard')
        ->assertPathIs('/dashboard')
        ->click('@sidebar-menu-button')
        ->click('button[data-test="logout-button"]:visible')
        ->assertPathIs('/')
        ->assertNoSmoke();
});
