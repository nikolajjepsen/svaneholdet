<?php

namespace App\Http\Controllers;

use App\Auth\Invitations;
use App\Enums\InvitationStatus;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class InvitationController
{
    public function confirm(string $token, Invitations $invitations): Response|RedirectResponse
    {
        $invitation = $invitations->find($token);
        $status = $invitation?->status();

        if ($status === InvitationStatus::Pending) {
            return response()->view('pages.invitations.confirm', [
                'token' => $token,
                'team' => $invitation->team,
            ], headers: [
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }

        return $this->unavailable($status);
    }

    public function redeem(string $token, Invitations $invitations): Response|RedirectResponse
    {
        $result = $invitations->redeem($token);

        if ($result instanceof User) {
            return to_route('dashboard')->header('Referrer-Policy', 'no-referrer');
        }

        return $this->unavailable($result);
    }

    private function unavailable(?InvitationStatus $status): Response|RedirectResponse
    {
        if ($status === InvitationStatus::Redeemed) {
            return to_route('login')->header('Referrer-Policy', 'no-referrer');
        }

        return response()->view('pages.invitations.unavailable', [
            'message' => match ($status) {
                InvitationStatus::Expired => __('This invitation has expired. Ask a team admin to resend it.'),
                InvitationStatus::Withdrawn => __('This invitation was withdrawn.'),
                default => __('This invitation link is unavailable.'),
            },
        ], headers: [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
