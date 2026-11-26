<?php

use App\Auth\CurrentTeam;
use App\Enums\ShiftStatus;
use App\Models\Season;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\Shifts;
use App\Scheduling\Slots;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $teamId;

    #[Locked]
    public ?string $statusMessage = null;

    public bool $showCreateSlotModal = false;

    public bool $showSignUpWarningModal = false;

    #[Locked]
    public ?int $warningSlotId = null;

    /** @var list<string> */
    #[Locked]
    public array $signUpWarnings = [];

    public string $date = '';

    public string $startTime = '08:00';

    public string $endTime = '16:00';

    public int $capacity = 4;

    protected Team $team;

    public function mount(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        Gate::authorize('viewAny', [Slot::class, $team]);

        $this->team = $team;
        $this->teamId = $team->id;
        $this->date = now()->setTimezone($team->timezone)->format('Y-m-d');
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);
        Gate::authorize('viewAny', [Slot::class, $team]);

        $this->team = $team;
    }

    public function createSlot(Slots $slots): void
    {
        $this->statusMessage = null;

        $season = $this->currentSeason();
        abort_if($season === null, 404);
        Gate::authorize('create', [Slot::class, $season]);

        $validated = $this->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'different:startTime'],
            'capacity' => ['required', 'integer', 'min:1'],
        ]);

        $slots->createSingle(
            season: $season,
            actor: $this->user(),
            date: $validated['date'],
            startTime: $validated['startTime'],
            endTime: $validated['endTime'],
            capacity: (int) $validated['capacity'],
        );

        $this->showCreateSlotModal = false;
        $this->statusMessage = __('Slot created.');
    }

    public function signUp(int $slotId, Shifts $shifts): void
    {
        $this->statusMessage = null;

        $slot = Slot::query()->with('season')->findOrFail($slotId);
        abort_unless($slot->season->team_id === $this->teamId, 403);
        abort_unless($slot->season_id === $this->team->current_season_id, 403);
        Gate::authorize('signUp', $slot);

        $result = $shifts->signUp($slot, $this->user());

        if ($result->hasWarnings()) {
            $this->warningSlotId = $slot->id;
            $this->signUpWarnings = $result->warnings;
            $this->showSignUpWarningModal = true;

            return;
        }

        $this->statusMessage = $result->shift?->status === ShiftStatus::Pending
            ? __('Your shift request has been sent.')
            : __('You are signed up for this slot.');
    }

    public function confirmSignUpWithWarnings(Shifts $shifts): void
    {
        $this->statusMessage = null;

        if ($this->warningSlotId === null) {
            $this->dismissSignUpWarning();

            return;
        }

        $slot = Slot::query()->with('season')->findOrFail($this->warningSlotId);
        abort_unless($slot->season->team_id === $this->teamId, 403);
        abort_unless($slot->season_id === $this->team->current_season_id, 403);
        Gate::authorize('signUp', $slot);

        $result = $shifts->signUp($slot, $this->user(), acceptWarnings: true);

        $this->dismissSignUpWarning();

        $this->statusMessage = $result->shift?->status === ShiftStatus::Pending
            ? __('Your shift request has been sent.')
            : __('You are signed up for this slot.');
    }

    public function dismissSignUpWarning(): void
    {
        $this->showSignUpWarningModal = false;
        $this->warningSlotId = null;
        $this->signUpWarnings = [];
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

    public function render(): View
    {
        $season = $this->currentSeason();
        $user = $this->user();
        $isAdmin = $user->isAdminOf($this->team);
        $timezone = $this->team->timezone;
        $showConfirmedVolunteerNames = $this->team->settings->showConfirmedVolunteerNames;

        $membership = $user->activeMemberships()
            ->whereBelongsTo($this->team)
            ->first();

        $days = collect();

        if ($season !== null) {
            $slots = $season->slots()
                ->withCount(['shifts as confirmed_shifts_count' => fn ($query) => $query->where('status', ShiftStatus::Confirmed)])
                ->when($isAdmin, fn ($query) => $query->withCount([
                    'shifts as pending_shifts_count' => fn ($shiftsQuery) => $shiftsQuery->where('status', ShiftStatus::Pending),
                ]))
                ->with(['shifts' => fn ($query) => $query
                    ->where('membership_id', $membership?->id)
                    ->whereIn('status', [ShiftStatus::Pending->value, ShiftStatus::Confirmed->value]),
                ])
                ->when($showConfirmedVolunteerNames, fn ($query) => $query->with([
                    'confirmedShifts' => fn ($shiftsQuery) => $shiftsQuery
                        ->orderBy('confirmed_at')
                        ->orderBy('id')
                        ->with('membership.user'),
                ]))
                ->orderBy('starts_at')
                ->orderBy('ends_at')
                ->orderBy('id')
                ->get();

            $days = $slots
                ->groupBy(fn (Slot $slot): string => $slot->starts_at->setTimezone($timezone)->format('Y-m-d'))
                ->map(function ($daySlots, string $dateKey) use ($timezone): array {
                    $firstStart = $daySlots->first()->starts_at->setTimezone($timezone);

                    $timeGroups = $daySlots
                        ->groupBy(fn (Slot $slot): string => $slot->starts_at->toIso8601String().'|'.$slot->ends_at->toIso8601String())
                        ->values();

                    return [
                        'dateKey' => $dateKey,
                        'label' => $firstStart->translatedFormat('l j. F Y'),
                        'timeGroups' => $timeGroups,
                    ];
                })
                ->values();
        }

        return $this->view([
            'team' => $this->team,
            'season' => $season,
            'isAdmin' => $isAdmin,
            'timezone' => $timezone,
            'showConfirmedVolunteerNames' => $showConfirmedVolunteerNames,
            'shiftsRequireConfirmation' => $this->team->settings->shiftsRequireConfirmation,
            'days' => $days,
        ])->title(__('Schedule'));
    }
}; ?>

<section class="max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Schedule') }}</flux:heading>
            <flux:text class="mt-1">
                {{ $team->name }}@if ($season !== null) · {{ $season->name }}@endif
            </flux:text>
        </div>

        @if ($season !== null)
            @can('create', [\App\Models\Slot::class, $season])
                <flux:button
                    variant="primary"
                    icon="plus"
                    wire:click="$set('showCreateSlotModal', true)"
                    data-test="open-create-slot"
                >
                    {{ __('Add slot') }}
                </flux:button>
            @endcan
        @endif
    </div>

    @if ($statusMessage !== null)
        <flux:callout icon="check-circle" color="green" role="status">
            <flux:callout.text>{{ $statusMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($errors->has('slot'))
        <flux:callout icon="exclamation-circle" color="red" role="alert">
            <flux:callout.text>{{ $errors->first('slot') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($days->isEmpty())
        <flux:card>
            <flux:text>{{ __('No slots have been scheduled yet.') }}</flux:text>
        </flux:card>
    @else
        <div class="space-y-6">
            @foreach ($days as $day)
                <div wire:key="day-{{ $day['dateKey'] }}" class="space-y-3">
                    <flux:heading size="lg" level="2" class="capitalize">{{ $day['label'] }}</flux:heading>

                    @foreach ($day['timeGroups'] as $groupIndex => $timeGroup)
                        <div
                            wire:key="day-{{ $day['dateKey'] }}-group-{{ $groupIndex }}"
                            @class([
                                'grid gap-3',
                                'grid-cols-1' => $timeGroup->count() === 1,
                                'grid-cols-2' => $timeGroup->count() > 1,
                            ])
                        >
                            @foreach ($timeGroup as $slot)
                                @php
                                    $startsLocal = $slot->starts_at->setTimezone($timezone);
                                    $endsLocal = $slot->ends_at->setTimezone($timezone);
                                    $isOvernight = ! $startsLocal->isSameDay($endsLocal);
                                    $confirmedCount = (int) $slot->confirmed_shifts_count;
                                    $pendingCount = $isAdmin ? (int) $slot->pending_shifts_count : 0;
                                    $displayedCount = $isAdmin ? $confirmedCount : min($confirmedCount, $slot->capacity);
                                    $memberShift = $slot->shifts->first();
                                    $isFull = $confirmedCount >= $slot->capacity;
                                @endphp

                                <flux:card wire:key="slot-{{ $slot->id }}" data-test="slot-card-{{ $slot->id }}" class="space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <div class="font-medium text-zinc-900 dark:text-white">
                                                {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }}
                                            </div>
                                            @if ($isOvernight)
                                                <flux:text class="text-xs">{{ __('Ends next day') }}</flux:text>
                                            @endif
                                        </div>

                                        <div class="flex items-center gap-2">
                                            @if ($isAdmin && $pendingCount > 0)
                                                <flux:badge size="sm" color="amber" data-test="pending-count-slot-{{ $slot->id }}">
                                                    {{ __(':count pending', ['count' => $pendingCount]) }}
                                                </flux:badge>
                                            @endif

                                            <flux:badge size="sm" :color="$isFull ? 'zinc' : 'blue'">
                                                {{ $displayedCount }} / {{ $slot->capacity }}
                                            </flux:badge>
                                        </div>
                                    </div>

                                    @if ($showConfirmedVolunteerNames && $slot->confirmedShifts->isNotEmpty())
                                        <flux:text class="text-sm" data-test="confirmed-names-slot-{{ $slot->id }}">
                                            {{ $slot->confirmedShifts->map(fn ($shift): string => $shift->membership->user->firstName())->join(', ') }}
                                        </flux:text>
                                    @endif

                                    <div class="flex items-center justify-end gap-2">
                                        @if ($isAdmin)
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                :href="route('schedule.slots.show', $slot)"
                                                wire:navigate
                                                data-test="open-slot-{{ $slot->id }}"
                                            >
                                                {{ __('Manage') }}
                                            </flux:button>
                                        @endif
                                        @if ($memberShift !== null && $memberShift->status === ShiftStatus::Pending)
                                            <flux:badge size="sm" color="amber" data-test="pending-slot-{{ $slot->id }}">
                                                {{ __('Pending') }}
                                            </flux:badge>
                                        @elseif ($memberShift !== null)
                                            <flux:badge size="sm" color="green" data-test="signed-up-slot-{{ $slot->id }}">
                                                {{ __('Signed up') }}
                                            </flux:badge>
                                        @elseif ($isFull && ! $shiftsRequireConfirmation)
                                            <flux:badge size="sm" color="zinc" data-test="full-slot-{{ $slot->id }}">
                                                {{ __('Full') }}
                                            </flux:badge>
                                        @else
                                            <flux:button
                                                variant="primary"
                                                size="sm"
                                                wire:click="signUp({{ $slot->id }})"
                                                data-test="sign-up-slot-{{ $slot->id }}"
                                            >
                                                {{ $shiftsRequireConfirmation ? __('Request shift') : __('Sign up') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </flux:card>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    @if ($season !== null)
        @can('create', [\App\Models\Slot::class, $season])
            <flux:modal wire:model="showCreateSlotModal" class="w-full max-w-md">
                <form wire:submit="createSlot" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Add slot') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Times are in :timezone.', ['timezone' => $timezone]) }}</flux:text>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Date') }}</flux:label>
                        <flux:input type="date" name="date" wire:model="date" required />
                        <flux:error name="date" />
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>{{ __('Start time') }}</flux:label>
                            <flux:input type="time" name="startTime" wire:model="startTime" required />
                            <flux:error name="startTime" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('End time') }}</flux:label>
                            <flux:input type="time" name="endTime" wire:model="endTime" required />
                            <flux:error name="endTime" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Capacity') }}</flux:label>
                        <flux:input type="number" name="capacity" min="1" wire:model="capacity" required />
                        <flux:error name="capacity" />
                    </flux:field>

                    <div class="flex justify-end gap-2">
                        <flux:button type="button" variant="ghost" wire:click="$set('showCreateSlotModal', false)">
                            {{ __('Cancel') }}
                        </flux:button>
                        <flux:button type="submit" variant="primary" data-test="create-slot-submit">
                            {{ __('Save') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @endcan
    @endif

    <flux:modal wire:model="showSignUpWarningModal" class="w-full max-w-md" data-test="sign-up-warning-modal">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Slot is full') }}</flux:heading>

            @foreach ($signUpWarnings as $warningIndex => $warning)
                <flux:callout wire:key="sign-up-warning-{{ $warningIndex }}" icon="exclamation-triangle" color="amber">
                    <flux:callout.text>{{ $warning }}</flux:callout.text>
                </flux:callout>
            @endforeach

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="dismissSignUpWarning">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="primary"
                    wire:click="confirmSignUpWithWarnings"
                    data-test="confirm-sign-up-warning"
                >
                    {{ __('Send request') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
