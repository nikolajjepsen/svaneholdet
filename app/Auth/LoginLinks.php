<?php

namespace App\Auth;

use App\Mail\LoginLinkMail;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

class LoginLinks
{
    public function request(string $email, ?string $ip = null): void
    {
        $email = Str::lower($email);
        if (RateLimiter::increment('login-link:'.hash('sha256', $email.'|'.($ip ?? '')), decaySeconds: 600) > 3) {
            return;
        }

        $user = User::query()->where('email', $email)->has('activeMemberships')->first();

        if ($user === null) {
            return;
        }

        $token = bin2hex(random_bytes(32));

        LoginLink::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
        ]);

        $path = route('login.confirm', ['token' => $token], absolute: false);
        $url = rtrim(config()->string('app.url'), '/').$path;

        defer(fn () => Mail::to($user)->send(new LoginLinkMail($url)));
    }

    public function find(string $token): ?LoginLink
    {
        return LoginLink::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    public function consume(string $token): ?User
    {
        return DB::transaction(function () use ($token): ?User {
            $link = LoginLink::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $link?->isUsable()) {
                return null;
            }

            $user = $link->user()->has('activeMemberships')->first();

            if ($user === null) {
                return null;
            }

            $link->update(['used_at' => now()]);

            return $user;
        });
    }
}
