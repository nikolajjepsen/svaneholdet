<?php

use App\Auth\CurrentTeam;
use App\Models\Season;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\DayOverview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $teamId;

    public string $selectedDate = '';

    protected Team $team;

    public function mount(CurrentTeam $currentTeam, DayOverview $dayOverview): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        Gate::authorize('viewDayOverview', $team);

        $this->team = $team;
        $this->teamId = $team->id;

        $season = $this->currentSeason();
        $this->selectedDate = $season !== null
            ? $dayOverview->defaultDate($season, $team->timezone)
            : now()->toImmutable()->setTimezone($team->timezone)->format('Y-m-d');
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);
        Gate::authorize('viewDayOverview', $team);

        $this->team = $team;
    }

    public function previousDay(): void
    {
        $this->selectedDate = $this->resolvedDateLocal()->subDay()->format('Y-m-d');
    }

    public function nextDay(): void
    {
        $this->selectedDate = $this->resolvedDateLocal()->addDay()->format('Y-m-d');
    }

    private function resolvedDateLocal(): CarbonImmutable
    {
        $timezone = $this->team->timezone;
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $this->selectedDate, $timezone);

        if ($parsed !== false && $parsed->format('Y-m-d') === $this->selectedDate) {
            return $parsed;
        }

        return now()->toImmutable()->setTimezone($timezone)->startOfDay();
    }

    private function currentSeason(): ?Season
    {
        $this->team->loadMissing('currentSeason');

        return $this->team->currentSeason;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        return $user;
    }

    public function render(DayOverview $dayOverview): View
    {
        $season = $this->currentSeason();
        $timezone = $this->team->timezone;
        $dateLocal = $this->resolvedDateLocal();
        $dateKey = $dateLocal->format('Y-m-d');

        /** @var Collection<int, Slot> $daySlots */
        $daySlots = new Collection;
        $uniqueConfirmedMembersCount = 0;

        if ($season !== null) {
            $snapshot = $dayOverview->forDate($season, $dateKey, $timezone);
            $daySlots = $snapshot['slots'];
            $uniqueConfirmedMembersCount = $snapshot['uniqueConfirmedMembersCount'];
        }

        return $this->view([
            'team' => $this->team,
            'season' => $season,
            'timezone' => $timezone,
            'dateKey' => $dateKey,
            'dayLabel' => $dateLocal->translatedFormat('l j. F Y'),
            'daySlots' => $daySlots,
            'uniqueConfirmedMembersCount' => $uniqueConfirmedMembersCount,
        ])->title(__('Day overview'));
    }
}; ?>

<section class="max-w-4xl space-y-6" wire:poll.10s>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Day overview') }}</flux:heading>
            <flux:text class="mt-1">
                {{ $team->name }}@if ($season !== null) · {{ $season->name }}@endif
            </flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button
                variant="ghost"
                size="sm"
                icon="chevron-left"
                wire:click="previousDay"
                data-test="previous-day"
            >
                {{ __('Previous day') }}
            </flux:button>

            <flux:input
                type="date"
                wire:model.live="selectedDate"
                :aria-label="__('Select date')"
                class="w-auto"
                data-test="date-picker"
            />

            <flux:button
                variant="ghost"
                size="sm"
                icon-trailing="chevron-right"
                wire:click="nextDay"
                data-test="next-day"
            >
                {{ __('Next day') }}
            </flux:button>
        </div>
    </div>

    <flux:card class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="lg" level="2" class="capitalize" data-test="day-heading">
            {{ $dayLabel }}
        </flux:heading>

        <div class="flex items-center gap-2" data-test="unique-confirmed-members">
            <flux:text>{{ __('Unique confirmed members') }}</flux:text>
            <flux:badge size="sm" color="blue" data-test="unique-confirmed-members-count">
                {{ $uniqueConfirmedMembersCount }}
            </flux:badge>
        </div>
    </flux:card>

    @if ($daySlots->isEmpty())
        <flux:card data-test="empty-day-state">
            <flux:text>{{ __('No slots on this day.') }}</flux:text>
        </flux:card>
    @else
        <div class="space-y-3">
            @foreach ($daySlots as $slot)
                @php
                    $startsLocal = $slot->starts_at->setTimezone($timezone);
                    $endsLocal = $slot->ends_at->setTimezone($timezone);
                    $isOvernight = ! $startsLocal->isSameDay($endsLocal);
                    $startedPreviousDay = $startsLocal->format('Y-m-d') !== $dateKey;
                    $confirmedCount = (int) $slot->confirmed_shifts_count;
                    $pendingCount = (int) $slot->pending_shifts_count;
                    $isFull = $confirmedCount >= $slot->capacity;
                @endphp

                <flux:card wire:key="day-slot-{{ $slot->id }}" data-test="day-slot-{{ $slot->id }}" class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-white">
                            {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }}
                        </div>

                        @if ($startedPreviousDay)
                            <flux:text class="text-xs">{{ __('Started previous day') }}</flux:text>
                        @elseif ($isOvernight)
                            <flux:text class="text-xs">{{ __('Ends next day') }}</flux:text>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge
                            size="sm"
                            :color="$pendingCount > 0 ? 'amber' : 'zinc'"
                            data-test="pending-count-slot-{{ $slot->id }}"
                        >
                            {{ __(':count pending', ['count' => $pendingCount]) }}
                        </flux:badge>

                        <flux:badge
                            size="sm"
                            :color="$confirmedCount > $slot->capacity ? 'amber' : ($isFull ? 'zinc' : 'blue')"
                            data-test="confirmed-count-slot-{{ $slot->id }}"
                        >
                            {{ $confirmedCount }} / {{ $slot->capacity }}
                        </flux:badge>

                        <flux:button
                            variant="ghost"
                            size="sm"
                            :href="route('schedule.slots.show', $slot)"
                            wire:navigate
                            data-test="open-slot-{{ $slot->id }}"
                        >
                            {{ __('Manage') }}
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</section>
