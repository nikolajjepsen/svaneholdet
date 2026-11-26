<?php

use App\Auth\CurrentTeam;
use App\Enums\ShiftStatus;
use App\Models\Shift;
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

    protected Team $team;

    public function mount(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        Gate::authorize('viewAny', [Shift::class, $team]);

        $this->team = $team;
        $this->teamId = $team->id;
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);
        Gate::authorize('viewAny', [Shift::class, $team]);

        $this->team = $team;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        return $user;
    }

    public function render(): View
    {
        $user = $this->user();
        $timezone = $this->team->timezone;
        $now = now();

        $membership = $user->activeMemberships()
            ->whereBelongsTo($this->team)
            ->first();

        $shifts = $membership === null
            ? collect()
            : $membership->shifts()
                ->with('slot')
                ->get();

        $upcomingShifts = $shifts
            ->filter(fn (Shift $shift): bool => $shift->slot->ends_at->greaterThanOrEqualTo($now))
            ->sortBy(fn (Shift $shift): int => $shift->slot->starts_at->getTimestamp())
            ->values();

        $pastShifts = $shifts
            ->filter(fn (Shift $shift): bool => $shift->slot->ends_at->lessThan($now))
            ->sortByDesc(fn (Shift $shift): int => $shift->slot->starts_at->getTimestamp())
            ->values();

        return $this->view([
            'team' => $this->team,
            'timezone' => $timezone,
            'upcomingShifts' => $upcomingShifts,
            'pastShifts' => $pastShifts,
        ])->title(__('My shifts'));
    }
}; ?>

<section class="max-w-4xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('My shifts') }}</flux:heading>
        <flux:text class="mt-1">{{ $team->name }}</flux:text>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Upcoming shifts') }}</flux:heading>

        @if ($upcomingShifts->isEmpty())
            <flux:card>
                <flux:text>{{ __('You have no upcoming shifts.') }}</flux:text>
            </flux:card>
        @else
            <div class="space-y-3">
                @foreach ($upcomingShifts as $shift)
                    @php
                        $startsLocal = $shift->slot->starts_at->setTimezone($timezone);
                        $endsLocal = $shift->slot->ends_at->setTimezone($timezone);
                        $hours = rtrim(rtrim(number_format($shift->slot->plannedHours(), 2, '.', ''), '0'), '.');
                        $badgeColor = match ($shift->status) {
                            ShiftStatus::Confirmed => 'green',
                            ShiftStatus::Pending => 'amber',
                            ShiftStatus::Rejected => 'red',
                            ShiftStatus::Cancelled => 'zinc',
                        };
                    @endphp

                    <flux:card wire:key="upcoming-shift-{{ $shift->id }}" data-test="upcoming-shift-{{ $shift->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white capitalize">
                                    {{ $startsLocal->translatedFormat('l j. F Y') }}
                                </div>
                                <flux:text class="mt-0.5">
                                    {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }} · {{ __(':hours h', ['hours' => $hours]) }}
                                </flux:text>
                            </div>

                            <flux:badge size="sm" :color="$badgeColor">
                                {{ $shift->status->label() }}
                            </flux:badge>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Past shifts') }}</flux:heading>

        @if ($pastShifts->isEmpty())
            <flux:card>
                <flux:text>{{ __('You have no past shifts.') }}</flux:text>
            </flux:card>
        @else
            <div class="space-y-3">
                @foreach ($pastShifts as $shift)
                    @php
                        $startsLocal = $shift->slot->starts_at->setTimezone($timezone);
                        $endsLocal = $shift->slot->ends_at->setTimezone($timezone);
                        $hours = rtrim(rtrim(number_format($shift->slot->plannedHours(), 2, '.', ''), '0'), '.');
                        $badgeColor = match ($shift->status) {
                            ShiftStatus::Confirmed => 'green',
                            ShiftStatus::Pending => 'amber',
                            ShiftStatus::Rejected => 'red',
                            ShiftStatus::Cancelled => 'zinc',
                        };
                    @endphp

                    <flux:card wire:key="past-shift-{{ $shift->id }}" data-test="past-shift-{{ $shift->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white capitalize">
                                    {{ $startsLocal->translatedFormat('l j. F Y') }}
                                </div>
                                <flux:text class="mt-0.5">
                                    {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }} · {{ __(':hours h', ['hours' => $hours]) }}
                                </flux:text>
                            </div>

                            <flux:badge size="sm" :color="$badgeColor">
                                {{ $shift->status->label() }}
                            </flux:badge>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>
</section>
