<x-layouts::auth :title="__('Team invitation')">
    <div>
        <flux:heading size="xl" level="1">{{ __('Join :team', ['team' => $team->name]) }}</flux:heading>
        <flux:text class="mt-2">{{ __('Accept this invitation to join the team as a volunteer.') }}</flux:text>
    </div>

    <form method="POST" action="{{ route('invitations.redeem', ['token' => $token]) }}">
        @csrf
        <flux:button type="submit" variant="primary" class="w-full" data-test="accept-invitation">
            {{ __('Accept') }}
        </flux:button>
    </form>
</x-layouts::auth>
