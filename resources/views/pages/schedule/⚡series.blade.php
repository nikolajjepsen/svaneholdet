<?php

use App\Auth\CurrentTeam;
use App\Enums\ShiftStatus;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\Series;
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
    public int $seriesId;

    #[Locked]
    public ?string $statusMessage = null;

    public string $startTime = '08:00';

    public string $endTime = '16:00';

    public int $capacity = 1;

    public bool $showWarningModal = false;

    /** @var list<string> */
    #[Locked]
    public array $warnings = [];

    public bool $showDeleteModal = false;

    public bool $notifyMembersOnDelete = true;

    protected Team $team;

    protected SlotSeries $series;

    public function mount(SlotSeries $slotSeries, CurrentTeam $currentTeam): void
    {
        $slotSeries->loadMissing('season');

        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        abort_unless($slotSeries->season->team_id === $team->id, 403);
        Gate::authorize('view', $slotSeries);

        $this->team = $team;
        $this->teamId = $team->id;
        $this->series = $slotSeries;
        $this->seriesId = $slotSeries->id;
        $this->startTime = $slotSeries->local_start_time;
        $this->endTime = $slotSeries->local_end_time;
        $this->capacity = $slotSeries->capacity;
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);

        $series = SlotSeries::query()->with('season')->findOrFail($this->seriesId);
        abort_unless($series->season->team_id === $this->teamId, 403);
        Gate::authorize('view', $series);

        $this->team = $team;
        $this->series = $series;
    }

    public function saveSeries(Series $seriesActions): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        Gate::authorize('update', $this->series);

        $validated = $this->validate([
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'different:startTime'],
            'capacity' => ['required', 'integer', 'min:1'],
        ]);

        $this->performSave(
            $validated['startTime'],
            $validated['endTime'],
            (int) $validated['capacity'],
            $seriesActions,
            acceptWarnings: false,
        );
    }

    public function acceptWarnings(Series $seriesActions): void
    {
        $this->statusMessage = null;
        $this->dismissWarnings();

        Gate::authorize('update', $this->series);

        $validated = $this->validate([
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'different:startTime'],
            'capacity' => ['required', 'integer', 'min:1'],
        ]);

        $this->performSave(
            $validated['startTime'],
            $validated['endTime'],
            (int) $validated['capacity'],
            $seriesActions,
            acceptWarnings: true,
        );
    }

    public function dismissWarnings(): void
    {
        $this->showWarningModal = false;
        $this->warnings = [];
    }

    public function openDeleteModal(): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();
        $this->notifyMembersOnDelete = true;
        $this->showDeleteModal = true;
    }

    public function deleteSeries(Series $seriesActions): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        Gate::authorize('delete', $this->series);

        $seriesActions->delete($this->series, $this->user(), notifyMembers: $this->notifyMembersOnDelete);

        $this->showDeleteModal = false;
        session()->flash('status', __('Slot series deleted.'));

        $this->redirectRoute('schedule.index', navigate: true);
    }

    private function performSave(
        string $startTime,
        string $endTime,
        int $capacity,
        Series $seriesActions,
        bool $acceptWarnings,
    ): void {
        $result = $seriesActions->update(
            series: $this->series,
            actor: $this->user(),
            startTime: $startTime,
            endTime: $endTime,
            capacity: $capacity,
            acceptWarnings: $acceptWarnings,
        );

        if ($result->hasWarnings()) {
            $this->warnings = $result->warnings;
            $this->showWarningModal = true;

            return;
        }

        $this->series = $this->series->fresh() ?? $this->series;
        $this->startTime = $this->series->local_start_time;
        $this->endTime = $this->series->local_end_time;
        $this->capacity = $this->series->capacity;
        $this->statusMessage = __('Slot series updated.');
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        return $user;
    }

    public function render(): View
    {
        $timezone = $this->team->timezone;

        $seriesSlots = $this->series->slots()
            ->withCount([
                'shifts as confirmed_shifts_count' => fn ($query) => $query->where('status', ShiftStatus::Confirmed),
                'shifts as pending_shifts_count' => fn ($query) => $query->where('status', ShiftStatus::Pending),
            ])
            ->with('confirmedShifts:id,slot_id,membership_id', 'confirmedShifts.membership:id,role')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $anyStarted = $seriesSlots->contains(fn (Slot $slot): bool => $slot->hasStarted());
        $totalConfirmed = (int) $seriesSlots->sum('confirmed_shifts_count');
        $totalPending = (int) $seriesSlots->sum('pending_shifts_count');

        return $this->view([
            'team' => $this->team,
            'currentSeries' => $this->series,
            'timezone' => $timezone,
            'seriesSlots' => $seriesSlots,
            'anyStarted' => $anyStarted,
            'totalConfirmed' => $totalConfirmed,
            'totalPending' => $totalPending,
        ])->title(__('Slot series'));
    }
}; ?>

<section class="max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('schedule.index')" wire:navigate>
            {{ __('Back to schedule') }}
        </flux:button>

        @if (! $anyStarted)
            <flux:button
                variant="danger"
                size="sm"
                wire:click="openDeleteModal"
                data-test="open-delete-series"
            >
                {{ __('Delete series') }}
            </flux:button>
        @endif
    </div>

    <div>
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="xl" level="1">{{ __('Slot series') }}</flux:heading>
            @if ($currentSeries->is_admin_only)
                <flux:badge size="sm" color="purple">{{ __('Admin-only') }}</flux:badge>
            @endif
        </div>
        <flux:text class="mt-1">
            {{ $currentSeries->local_start_time }}–{{ $currentSeries->local_end_time }} · {{ $team->name }}
        </flux:text>
    </div>

    @if ($statusMessage !== null)
        <flux:callout icon="check-circle" color="green" role="status">
            <flux:callout.text>{{ $statusMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($errors->has('series'))
        <flux:callout icon="exclamation-circle" color="red" role="alert">
            <flux:callout.text>{{ $errors->first('series') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($errors->has('times'))
        <flux:callout icon="exclamation-circle" color="red" role="alert" data-test="series-times-errors">
            <div class="space-y-1">
                @foreach ($errors->get('times') as $timeError)
                    <flux:callout.text>{{ $timeError }}</flux:callout.text>
                @endforeach
            </div>
        </flux:callout>
    @endif

    <flux:card class="space-y-4">
        <div>
            <flux:heading size="md" level="2">{{ __('Edit upcoming slots in series') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Changes apply only to slots in this series that have not started yet. Only the fields you change are updated.') }}
            </flux:text>
        </div>

        <form wire:submit="saveSeries" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:field>
                    <flux:label>{{ __('Start time') }}</flux:label>
                    <flux:input type="time" name="startTime" wire:model="startTime" data-test="series-start-time-input" required />
                    <flux:error name="startTime" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('End time') }}</flux:label>
                    <flux:input type="time" name="endTime" wire:model="endTime" data-test="series-end-time-input" required />
                    <flux:error name="endTime" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Capacity') }}</flux:label>
                    <flux:input type="number" min="1" name="capacity" wire:model="capacity" data-test="series-capacity-input" required />
                    <flux:error name="capacity" />
                </flux:field>
            </div>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" size="sm" data-test="save-series-submit">
                    {{ __('Save series') }}
                </flux:button>
            </div>
        </form>
    </flux:card>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Slots in this series') }}</flux:heading>

        @if ($seriesSlots->isEmpty())
            <flux:card>
                <flux:text>{{ __('No slots remain in this series.') }}</flux:text>
            </flux:card>
        @else
            <div class="space-y-2">
                @foreach ($seriesSlots as $slot)
                    @php
                        $startsLocal = $slot->starts_at->setTimezone($timezone);
                        $endsLocal = $slot->ends_at->setTimezone($timezone);
                        $confirmedCount = $slot->capacityCountingShifts($slot->confirmedShifts)->count();
                    @endphp
                    <flux:card wire:key="series-slot-{{ $slot->id }}" data-test="series-slot-{{ $slot->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-medium capitalize text-zinc-900 dark:text-white">
                                    {{ $startsLocal->translatedFormat('l j. F Y') }}
                                </div>
                                <flux:text class="text-sm">
                                    {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }}
                                    @if ($slot->hasStarted()) · {{ __('Started') }}@endif
                                </flux:text>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:badge size="sm" :color="$confirmedCount > $slot->capacity ? 'amber' : 'blue'">
                                    {{ $confirmedCount }} / {{ $slot->capacity }}
                                </flux:badge>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    :href="route('schedule.slots.show', $slot)"
                                    wire:navigate
                                    data-test="open-series-slot-{{ $slot->id }}"
                                >
                                    {{ __('Manage') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>

    <flux:modal wire:model="showWarningModal" class="w-full max-w-md" data-test="series-warning-modal">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Please confirm') }}</flux:heading>

            @foreach ($warnings as $warningIndex => $warning)
                <flux:callout wire:key="series-warning-{{ $warningIndex }}" icon="exclamation-triangle" color="amber">
                    <flux:callout.text>{{ $warning }}</flux:callout.text>
                </flux:callout>
            @endforeach

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="dismissWarnings">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="primary"
                    wire:click="acceptWarnings"
                    data-test="accept-series-warnings"
                >
                    {{ __('Continue anyway') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="w-full max-w-md" data-test="delete-series-modal">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Delete series') }}</flux:heading>

            <flux:text>
                {{ __('This series has :confirmed confirmed and :pending pending shifts across :slots slots. Deleting it removes all slots and shifts in this series.', ['confirmed' => $totalConfirmed, 'pending' => $totalPending, 'slots' => $seriesSlots->count()]) }}
            </flux:text>

            <flux:checkbox
                wire:model="notifyMembersOnDelete"
                :label="__('Email affected members that these slots are cancelled')"
                data-test="delete-series-notify-checkbox"
            />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="danger"
                    wire:click="deleteSeries"
                    data-test="confirm-delete-series"
                >
                    {{ __('Delete series') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
