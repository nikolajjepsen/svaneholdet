<?php

namespace App\Http\Controllers;

use App\Auth\LoginLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class LoginLinkController
{
    public function request(Request $request, LoginLinks $loginLinks): RedirectResponse
    {
        $email = $request->input('email');

        if (is_string($email) && Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'max:255'],
        ])->passes()) {
            $loginLinks->request($email, $request->ip());
        }

        return to_route('login')->with('status', __('Check your email. If you have access, we sent you a login link.'));
    }

    public function confirm(string $token, LoginLinks $loginLinks): Response
    {
        $link = $loginLinks->find($token);

        if ($link?->isUsable()) {
            return $this->privatePage('pages.auth.confirm-login', ['token' => $token]);
        }

        return $this->newLinkForm($link?->user?->email);
    }

    public function consume(string $token, LoginLinks $loginLinks): Response|RedirectResponse
    {
        $user = $loginLinks->consume($token);

        if ($user === null) {
            return $this->newLinkForm($loginLinks->find($token)?->user?->email);
        }

        Auth::guard('web')->login($user);

        return to_route('dashboard')->header('Referrer-Policy', 'no-referrer');
    }

    private function newLinkForm(?string $email): Response
    {
        return $this->privatePage('pages.auth.login', ['email' => $email, 'invalidLink' => true]);
    }

    /** @param array<string, mixed> $data */
    private function privatePage(string $view, array $data): Response
    {
        return response()->view($view, $data, headers: [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
