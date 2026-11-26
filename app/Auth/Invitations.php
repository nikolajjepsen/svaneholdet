<?php

namespace App\Auth;

use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Invitations
{
    public function __construct(private CurrentTeam $currentTeam) {}

    public function invite(Team $team, string $name, string $email, User $inviter): void
    {
        $email = Str::lower($email);
        $token = bin2hex(random_bytes(32));

        $locale = DB::transaction(function () use ($team, $name, $email, $inviter, $token): string {
            // Serialize issuance without blocking foreign key checks during redemption.
            Team::query()->lock('for no key update')->findOrFail($team->id);
            Gate::forUser($inviter)->authorize('invite', $team);

            $invitation = Invitation::query()->unresolved()
                ->whereBelongsTo($team)
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            $recipient = User::query()->where('email', $email)->first();

            if ($recipient?->activeMemberships()->whereBelongsTo($team)->exists()) {
                throw ValidationException::withMessages(['email' => __('This person is already a member of the team.')]);
            }

            if ($invitation?->status() === InvitationStatus::Pending) {
                throw ValidationException::withMessages(['email' => __('An invitation is already pending for this email address.')]);
            }

            // Expired invitations can be reissued in the same slot in the partial unique index.
            $invitation ??= new Invitation;
            $invitation->fill([
                'team_id' => $team->id,
                'invited_by' => $inviter->id,
                'name' => $name,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(14),
            ])->save();

            return $recipient?->preferredLocale() ?? 'da';
        });

        $this->sendInvitationMail($email, $locale, $token, $team->name);
    }

    public function resend(Invitation $invitation, User $actor): void
    {
        $token = bin2hex(random_bytes(32));

        /** @var array{email: string, team_name: string, locale: string} $delivery */
        $delivery = DB::transaction(function () use ($invitation, $actor, $token): array {
            $team = Team::query()->lock('for no key update')->findOrFail($invitation->team_id);
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            Gate::forUser($actor)->authorize('resend', $locked);

            if (! in_array($locked->status(), [InvitationStatus::Pending, InvitationStatus::Expired], true)) {
                throw ValidationException::withMessages(['invitation' => __('This invitation can no longer be changed.')]);
            }

            $locked->update([
                'invited_by' => $actor->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(14),
            ]);

            $recipient = User::query()->where('email', $locked->email)->first();

            return [
                'email' => $locked->email,
                'team_name' => $team->name,
                'locale' => $recipient?->preferredLocale() ?? 'da',
            ];
        });

        $this->sendInvitationMail($delivery['email'], $delivery['locale'], $token, $delivery['team_name']);
    }

    public function withdraw(Invitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor): void {
            Team::query()->lock('for no key update')->findOrFail($invitation->team_id);
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            Gate::forUser($actor)->authorize('withdraw', $locked);

            if (! in_array($locked->status(), [InvitationStatus::Pending, InvitationStatus::Expired], true)) {
                throw ValidationException::withMessages(['invitation' => __('This invitation can no longer be changed.')]);
            }

            $locked->update(['withdrawn_at' => now()]);
        });
    }

    public function find(string $token): ?Invitation
    {
        return $this->byToken($token)->with('team')->first();
    }

    public function redeem(string $token): User|InvitationStatus|null
    {
        /** @var array{user: User, team: Team}|InvitationStatus|null $result */
        $result = DB::transaction(function () use ($token): array|InvitationStatus|null {
            $invitation = $this->byToken($token)->lockForUpdate()->first();

            if ($invitation === null) {
                return null;
            }

            $status = $invitation->status();

            if ($status !== InvitationStatus::Pending) {
                return $status;
            }

            $team = Team::query()->findOrFail($invitation->team_id);

            $user = User::query()->firstOrCreate(
                ['email' => $invitation->email],
                ['name' => $invitation->name],
            );

            $membership = $user->memberships()
                ->where('team_id', $invitation->team_id)
                ->lockForUpdate()
                ->first();

            $attributes = ['role' => MembershipRole::Volunteer, 'status' => MembershipStatus::Active];

            if ($membership === null) {
                $user->memberships()->create(['team_id' => $invitation->team_id, ...$attributes]);
            } elseif ($membership->status === MembershipStatus::Removed) {
                $membership->update($attributes);
            }

            $invitation->update(['redeemed_at' => now()]);

            return ['user' => $user, 'team' => $team];
        });

        if (is_array($result)) {
            Auth::guard('web')->login($result['user']);
            $this->currentTeam->switchTo($result['user'], $result['team']);

            return $result['user'];
        }

        return $result;
    }

    private function sendInvitationMail(string $email, string $locale, string $token, string $teamName): void
    {
        $path = route('invitations.confirm', ['token' => $token], absolute: false);
        $url = rtrim(config()->string('app.url'), '/').$path;

        Mail::to($email)->locale($locale)->send(new InvitationMail($url, $teamName));
    }

    /** @return Builder<Invitation> */
    private function byToken(string $token): Builder
    {
        return Invitation::query()->where('token_hash', hash('sha256', $token));
    }
}
