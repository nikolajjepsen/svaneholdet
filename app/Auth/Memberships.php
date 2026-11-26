<?php

namespace App\Auth;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Memberships
{
    public function promote(Membership $membership, User $actor): void
    {
        $this->mutateActive($membership, $actor, 'promote', function (Membership $locked): void {
            if ($locked->role !== MembershipRole::Admin) {
                $locked->update(['role' => MembershipRole::Admin]);
            }
        });
    }

    public function demote(Membership $membership, User $actor): void
    {
        $this->mutateActive($membership, $actor, 'demote', function (Membership $locked): void {
            if ($locked->role === MembershipRole::Admin) {
                $this->ensureAnotherAdminRemains($locked->team_id);
                $locked->update(['role' => MembershipRole::Volunteer]);
            }
        });
    }

    public function remove(Membership $membership, User $actor): void
    {
        $this->mutateActive($membership, $actor, 'remove', function (Membership $locked): void {
            if ($locked->role === MembershipRole::Admin) {
                $this->ensureAnotherAdminRemains($locked->team_id);
            }

            $locked->update(['status' => MembershipStatus::Removed]);
        });
    }

    /** @param Closure(Membership): void $callback */
    private function mutateActive(Membership $membership, User $actor, string $ability, Closure $callback): void
    {
        DB::transaction(function () use ($membership, $actor, $ability, $callback): void {
            Team::query()->lock('for no key update')->findOrFail($membership->team_id);
            $locked = Membership::query()->lockForUpdate()->findOrFail($membership->id);

            Gate::forUser($actor)->authorize($ability, $locked);

            if ($locked->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages(['member' => __('This member is no longer active on the team.')]);
            }

            $callback($locked);
        });
    }

    private function ensureAnotherAdminRemains(int $teamId): void
    {
        $activeAdminCount = Membership::query()
            ->where('team_id', $teamId)
            ->where('status', MembershipStatus::Active)
            ->where('role', MembershipRole::Admin)
            ->count();

        if ($activeAdminCount <= 1) {
            throw ValidationException::withMessages(['member' => __('The team must have at least one admin.')]);
        }
    }
}
