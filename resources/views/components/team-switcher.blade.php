@props([
    'currentTeam' => null,
    'teams' => collect(),
    'location' => 'sidebar',
])

@if ($currentTeam !== null)
    @if ($teams->count() > 1)
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="subtle"
                size="sm"
                icon:trailing="chevrons-up-down"
                :aria-label="__('Switch team')"
                @class([
                    'max-w-full',
                    'w-full justify-between' => $location === 'sidebar',
                ])
                data-test="{{ $location }}-team-switcher"
            >
                <span class="truncate">{{ $currentTeam->name }}</span>
            </flux:button>

            <flux:menu>
                <flux:menu.heading>{{ __('Teams') }}</flux:menu.heading>

                @foreach ($teams as $team)
                    <form method="POST" action="{{ route('current-team.update') }}" class="w-full">
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $team->id }}" />
                        <flux:menu.item
                            as="button"
                            type="submit"
                            :icon-trailing="$team->is($currentTeam) ? 'check' : null"
                            class="w-full cursor-pointer"
                            data-test="switch-team-{{ $team->id }}"
                        >
                            {{ $team->name }}
                        </flux:menu.item>
                    </form>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @else
        <flux:text
            @class([
                'truncate text-sm font-medium',
                'px-2 py-1.5' => $location === 'sidebar',
            ])
            data-test="{{ $location }}-team-name"
        >
            {{ $currentTeam->name }}
        </flux:text>
    @endif
@endif
