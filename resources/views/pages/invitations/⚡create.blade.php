<?php

use App\Auth\CurrentTeam;
use App\Auth\Invitations;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $teamId;

    public string $name = '';

    public string $email = '';

    #[Locked]
    public bool $sent = false;

    protected Team $team;

    public function mount(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        Gate::authorize('invite', $team);

        $this->team = $team;
        $this->teamId = $team->id;
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);
        Gate::authorize('invite', $team);

        $this->team = $team;
    }

    public function invite(Invitations $invitations): void
    {
        $this->sent = false;
        $this->name = trim($this->name);
        $this->email = trim($this->email);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.email' => __('Enter a valid email address.'),
        ]);

        $invitations->invite($this->team, $validated['name'], $validated['email'], $this->user());

        $this->reset('name', 'email');
        $this->sent = true;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        return $user;
    }

    public function render(): View
    {
        return $this->view(['team' => $this->team])->title(__('Invite a volunteer'));
    }
}; ?>

<section class="max-w-lg space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Invite a volunteer') }}</flux:heading>
        <flux:text class="mt-2">{{ $team->name }}</flux:text>
    </div>

    @if ($sent)
        <flux:callout icon="check-circle" color="green" role="status">
            <flux:callout.text>{{ __('Invitation sent.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="invite" class="space-y-6">
        <flux:input wire:model="name" name="name" :label="__('Name')" autocomplete="name" required />
        <flux:input wire:model="email" name="email" :label="__('Email address')" type="email" autocomplete="email" required />

        <flux:button variant="primary" type="submit" data-test="send-invitation">
            {{ __('Send invitation') }}
        </flux:button>
    </form>
</section>
