<?php

use App\Auth\CurrentTeam;
use App\Auth\Invitations;
use App\Auth\Memberships;
use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\HourGoals;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $teamId;

    #[Locked]
    public ?string $statusMessage = null;

    #[Locked]
    public ?int $hourGoalMembershipId = null;

    #[Locked]
    public ?int $hourGoalSeasonId = null;

    public bool $showHourGoalModal = false;

    public string $hourGoal = '';

    protected Team $team;

    public function mount(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        Gate::authorize('manageMembers', $team);

        $this->team = $team;
        $this->teamId = $team->id;
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);
        Gate::authorize('manageMembers', $team);

        $this->team = $team;
    }

    public function editHourGoal(int $membershipId): void
    {
        Gate::authorize('manageMembers', $this->team);
        $season = $this->currentSeason();
        abort_if($season === null, 403);

        $membership = Membership::query()->findOrFail($membershipId);
        abort_unless($membership->team_id === $this->teamId && $membership->status === MembershipStatus::Active, 403);

        $this->resetValidation('hourGoal');
        $this->statusMessage = null;
        $this->hourGoalMembershipId = $membership->id;
        $this->hourGoalSeasonId = $season->id;
        $this->hourGoal = (string) $season->hourGoals()->whereBelongsTo($membership)->first()?->hours;
        $this->showHourGoalModal = true;
    }

    public function saveHourGoal(HourGoals $hourGoals): void
    {
        Gate::authorize('manageMembers', $this->team);
        $season = $this->currentSeason();
        abort_if($season === null || $season->id !== $this->hourGoalSeasonId || $this->hourGoalMembershipId === null, 403);

        $membership = Membership::query()->findOrFail($this->hourGoalMembershipId);
        $validated = $this->validate([
            'hourGoal' => ['nullable', 'integer', 'min:0'],
        ], [
            'hourGoal.integer' => __('Enter a whole number of hours, 0 or more.'),
            'hourGoal.min' => __('Enter a whole number of hours, 0 or more.'),
        ]);

        $hours = $validated['hourGoal'];
        $hourGoals->setOverride($season, $membership, $this->user(), $hours === null || $hours === '' ? null : (int) $hours);

        $this->reset('showHourGoalModal', 'hourGoalMembershipId', 'hourGoalSeasonId', 'hourGoal');
        $this->statusMessage = __('Hour goal saved.');
    }

    public function resend(int $invitationId, Invitations $invitations): void
    {
        $this->statusMessage = null;
        $invitation = Invitation::query()->findOrFail($invitationId);
        abort_unless($invitation->team_id === $this->teamId, 403);
        Gate::authorize('resend', $invitation);

        $invitations->resend($invitation, $this->user());

        $this->statusMessage = __('Invitation resent.');
    }

    public function withdraw(int $invitationId, Invitations $invitations): void
    {
        $this->statusMessage = null;
        $invitation = Invitation::query()->findOrFail($invitationId);
        abort_unless($invitation->team_id === $this->teamId, 403);
        Gate::authorize('withdraw', $invitation);

        $invitations->withdraw($invitation, $this->user());

        $this->statusMessage = __('Invitation withdrawn.');
    }

    public function promote(int $membershipId, Memberships $memberships): void
    {
        $this->statusMessage = null;
        $membership = Membership::query()->findOrFail($membershipId);
        abort_unless($membership->team_id === $this->teamId, 403);
        Gate::authorize('promote', $membership);

        $memberships->promote($membership, $this->user());

        $this->statusMessage = __('Member promoted to admin.');
    }

    public function demote(int $membershipId, Memberships $memberships): void
    {
        $this->statusMessage = null;
        $membership = Membership::query()->findOrFail($membershipId);
        abort_unless($membership->team_id === $this->teamId, 403);
        Gate::authorize('demote', $membership);

        $actor = $this->user();
        $memberships->demote($membership, $actor);

        if ($membership->user_id === $actor->id) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $this->statusMessage = __('Admin demoted to volunteer.');
    }

    public function remove(int $membershipId, Memberships $memberships): void
    {
        $this->statusMessage = null;
        $membership = Membership::query()->findOrFail($membershipId);
        abort_unless($membership->team_id === $this->teamId, 403);
        Gate::authorize('remove', $membership);

        $actor = $this->user();
        $memberships->remove($membership, $actor);

        if ($membership->user_id === $actor->id) {
            if ($actor->activeMemberships()->doesntExist()) {
                Auth::guard('web')->logout();
                Session::invalidate();
                Session::regenerateToken();

                $this->redirectRoute('login');

                return;
            }

            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $this->statusMessage = __('Member removed from the team.');
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        return $user;
    }

    private function currentSeason(): ?Season
    {
        $this->team->loadMissing('currentSeason');
        $season = $this->team->currentSeason;

        return $season?->team_id === $this->teamId ? $season : null;
    }

    public function render(): View
    {
        $memberships = $this->team->activeMemberships()
            ->with('user')
            ->orderByRaw("case when role = 'admin' then 0 else 1 end")
            ->orderBy('id')
            ->get();

        $invitations = $this->team->invitations()
            ->unresolved()
            ->oldest('id')
            ->get();

        $season = $this->currentSeason();

        return $this->view([
            'team' => $this->team,
            'memberships' => $memberships,
            'invitations' => $invitations,
            'adminCount' => $memberships->where('role', MembershipRole::Admin)->count(),
            'season' => $season,
            'hourGoalOverrides' => $season?->hourGoals()->get()->keyBy('membership_id'),
            'hourGoalMember' => $this->hourGoalMembershipId === null ? null : $memberships->find($this->hourGoalMembershipId),
        ])->title(__('Members'));
    }
}; ?>

<section class="max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Members') }}</flux:heading>
            <flux:text class="mt-2">{{ $team->name }}</flux:text>
        </div>

        <flux:button variant="primary" icon="user-plus" :href="route('invitations.create')" wire:navigate>
            {{ __('Invite a volunteer') }}
        </flux:button>
    </div>

    @if ($statusMessage !== null)
        <flux:callout icon="check-circle" color="green" role="status">
            <flux:callout.text>{{ $statusMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($errors->any())
        <flux:callout icon="exclamation-circle" color="red" role="alert">
            <flux:callout.text>{{ $errors->first() }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            @if ($season !== null)
                <flux:table.column>{{ __('Hour goal') }}</flux:table.column>
            @endif
            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($memberships as $membership)
                <flux:table.row :key="'member-'.$membership->id" data-test="member-row-{{ $membership->id }}">
                    <flux:table.cell>
                        <div class="flex items-center gap-3">
                            <flux:avatar
                                size="sm"
                                :name="$membership->user->name"
                                :src="$membership->user->profilePictureUrl()"
                                :initials="$membership->user->initials()"
                            />
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $membership->user->name }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $membership->user->email }}</div>
                            </div>
                        </div>
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($membership->role === MembershipRole::Admin)
                            <flux:badge color="amber" size="sm">{{ __('Admin') }}</flux:badge>
                        @else
                            <flux:badge size="sm">{{ __('Volunteer') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    @if ($season !== null)
                        @php
                            $override = $hourGoalOverrides->get($membership->id);
                            $effectiveGoal = HourGoals::effective($team->settings, $override);
                        @endphp
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                @if ($override?->hours === 0)
                                    {{ __('Exempt') }}
                                @elseif ($effectiveGoal === null)
                                    {{ __('No hour goal') }}
                                @elseif ($override === null)
                                    {{ __(':hours h (team default)', ['hours' => $effectiveGoal]) }}
                                @else
                                    {{ __(':hours h (custom)', ['hours' => $effectiveGoal]) }}
                                @endif
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="editHourGoal({{ $membership->id }})"
                                    data-test="edit-hour-goal-{{ $membership->id }}"
                                >
                                    {{ __('Edit hour goal') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    @endif

                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @if ($membership->role === MembershipRole::Volunteer)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="promote({{ $membership->id }})"
                                    data-test="promote-member-{{ $membership->id }}"
                                >
                                    {{ __('Make admin') }}
                                </flux:button>
                            @elseif ($adminCount > 1)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="demote({{ $membership->id }})"
                                    data-test="demote-member-{{ $membership->id }}"
                                >
                                    {{ __('Make volunteer') }}
                                </flux:button>
                            @endif

                            @if ($membership->role === MembershipRole::Volunteer || $adminCount > 1)
                                <flux:button
                                    size="sm"
                                    variant="danger"
                                    wire:click="remove({{ $membership->id }})"
                                    data-test="remove-member-{{ $membership->id }}"
                                >
                                    {{ __('Remove') }}
                                </flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach

            @foreach ($invitations as $invitation)
                <flux:table.row :key="'invitation-'.$invitation->id" data-test="invitation-row-{{ $invitation->id }}">
                    <flux:table.cell>
                        <div class="flex items-center gap-3">
                            <flux:avatar size="sm" :name="$invitation->name" />
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $invitation->name }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $invitation->email }}</div>
                            </div>
                        </div>
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($invitation->status() === InvitationStatus::Expired)
                            <flux:badge color="red" size="sm">{{ __('Expired') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('Pending') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    @if ($season !== null)
                        <flux:table.cell />
                    @endif

                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="resend({{ $invitation->id }})"
                                data-test="resend-invitation-{{ $invitation->id }}"
                            >
                                {{ __('Resend') }}
                            </flux:button>

                            <flux:button
                                size="sm"
                                variant="danger"
                                wire:click="withdraw({{ $invitation->id }})"
                                data-test="withdraw-invitation-{{ $invitation->id }}"
                            >
                                {{ __('Withdraw') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($season !== null)
        <flux:modal wire:model="showHourGoalModal" class="w-full max-w-md">
            <form wire:submit="saveHourGoal" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Edit hour goal') }}</flux:heading>
                    <flux:text class="mt-1">{{ $hourGoalMember?->user->name }} · {{ $season->name }}</flux:text>
                </div>

                <flux:field>
                    <flux:label>{{ __('Hour goal') }}</flux:label>
                    <flux:description>{{ __('Leave blank to inherit the team default. Enter 0 to exempt this member for the season.') }}</flux:description>
                    <flux:input type="number" name="hourGoal" min="0" step="1" wire:model="hourGoal" />
                    <flux:error name="hourGoal" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-hour-goal">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
