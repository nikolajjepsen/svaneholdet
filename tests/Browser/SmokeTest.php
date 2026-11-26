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
});
