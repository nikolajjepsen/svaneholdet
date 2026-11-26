<?php

use App\Models\User;

it('does not expose a starter-kit authentication or security endpoint', function (string $method, string $path) {
    $this->actingAs(User::factory()->create())
        ->call($method, $path)
        ->assertNotFound();
})->with([
    'registration' => ['POST', '/register'],
    'password reset' => ['POST', '/forgot-password'],
    'email verification' => ['POST', '/email/verification-notification'],
    'password confirmation' => ['POST', '/user/confirm-password'],
    'two-factor authentication' => ['POST', '/user/two-factor-authentication'],
    'passkeys' => ['GET', '/passkeys/login/options'],
    'passkey discovery' => ['GET', '/.well-known/passkey-endpoints'],
    'security settings' => ['GET', '/settings/security'],
]);
