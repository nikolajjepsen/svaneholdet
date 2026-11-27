<?php

use App\Auth\CurrentTeam;
use App\Models\Season;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\Rollout;
use App\Scheduling\RolloutPreview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $teamId;

    public int $calendarYear;

    public int $calendarMonth;

    /** @var list<string> */
    public array $selectedDates = [];

    /** @var list<array{start: string, end: string, capacity: int|string, isAdminOnly?: bool}> */
    public array $intervals = [
        ['start' => '08:00', 'end' => '16:00', 'capacity' => 4, 'isAdminOnly' => false],
    ];

    protected Team $team;

    public function mount(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        Gate::authorize('viewAny', [SlotSeries::class, $team]);

        $this->team = $team;
        $this->teamId = $team->id;

        $today = now()->setTimezone($team->timezone);
        $this->calendarYear = $today->year;
        $this->calendarMonth = $today->month;
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);
        Gate::authorize('viewAny', [SlotSeries::class, $team]);

        $this->team = $team;
    }

    public function previousMonth(): void
    {
        $date = CarbonImmutable::create($this->calendarYear, $this->calendarMonth, 1)->subMonth();
        $this->calendarYear = $date->year;
        $this->calendarMonth = $date->month;
    }

    public function nextMonth(): void
    {
        $date = CarbonImmutable::create($this->calendarYear, $this->calendarMonth, 1)->addMonth();
        $this->calendarYear = $date->year;
        $this->calendarMonth = $date->month;
    }

    public function toggleDate(string $date): void
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            return;
        }

        if (in_array($date, $this->selectedDates, true)) {
            $this->selectedDates = array_values(array_filter(
                $this->selectedDates,
                fn (string $selected): bool => $selected !== $date,
            ));
        } else {
            $this->selectedDates[] = $date;
            sort($this->selectedDates);
        }

        $this->resetErrorBag('selectedDates');
    }

    public function clearDates(): void
    {
        $this->selectedDates = [];
    }

    public function addInterval(): void
    {
        $last = $this->intervals[count($this->intervals) - 1] ?? ['start' => '08:00', 'end' => '16:00', 'capacity' => 4];

        $this->intervals[] = [
            'start' => '16:00',
            'end' => '00:00',
            'capacity' => max(1, (int) $last['capacity']),
            'isAdminOnly' => false,
        ];
    }

    public function removeInterval(int $index): void
    {
        if (count($this->intervals) <= 1) {
            return;
        }

        unset($this->intervals[$index]);
        $this->intervals = array_values($this->intervals);
    }

    public function save(Rollout $rollout): void
    {
        $season = $this->currentSeason();
        abort_if($season === null, 404);
        Gate::authorize('create', [SlotSeries::class, $season]);

        $validated = $this->validate([
            'selectedDates' => ['required', 'array', 'min:1'],
            'selectedDates.*' => ['required', 'date_format:Y-m-d'],
            'intervals' => ['required', 'array', 'min:1'],
            'intervals.*.start' => ['required', 'date_format:H:i'],
            'intervals.*.end' => ['required', 'date_format:H:i', 'different:intervals.*.start'],
            'intervals.*.capacity' => ['required', 'integer', 'min:1'],
            'intervals.*.isAdminOnly' => ['sometimes', 'boolean'],
        ]);

        /** @var list<array{start: string, end: string, capacity: int, isAdminOnly: bool}> $normalizedIntervals */
        $normalizedIntervals = array_map(
            fn (array $interval): array => [
                'start' => (string) $interval['start'],
                'end' => (string) $interval['end'],
                'capacity' => (int) $interval['capacity'],
                'isAdminOnly' => (bool) ($interval['isAdminOnly'] ?? false),
            ],
            array_values($validated['intervals']),
        );

        /** @var list<string> $dates */
        $dates = array_values($validated['selectedDates']);

        $rollout->save($season, $this->user(), $dates, $normalizedIntervals);

        $createdCount = count(array_unique($dates)) * count($normalizedIntervals);
        $message = $createdCount === 1
            ? __('1 slot created.')
            : __(':count slots created.', ['count' => $createdCount]);

        session()->flash('status', $message);
        $this->redirectRoute('schedule.index', navigate: true);
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

    /**
     * @return list<array{date: ?string, day: ?int, isToday: bool, isSelected: bool}>
     */
    private function buildCalendarCells(string $timezone): array
    {
        $firstOfMonth = CarbonImmutable::create($this->calendarYear, $this->calendarMonth, 1, 0, 0, 0, $timezone);
        $todayKey = now()->setTimezone($timezone)->format('Y-m-d');
        $selectedLookup = array_flip($this->selectedDates);

        // Monday = 1 ... Sunday = 7
        $leadingEmpty = $firstOfMonth->dayOfWeekIso - 1;
        $daysInMonth = $firstOfMonth->daysInMonth;

        $cells = [];

        for ($i = 0; $i < $leadingEmpty; $i++) {
            $cells[] = [
                'date' => null,
                'day' => null,
                'isToday' => false,
                'isSelected' => false,
            ];
        }

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateString = sprintf('%04d-%02d-%02d', $this->calendarYear, $this->calendarMonth, $day);
            $cells[] = [
                'date' => $dateString,
                'day' => $day,
                'isToday' => $dateString === $todayKey,
                'isSelected' => isset($selectedLookup[$dateString]),
            ];
        }

        $trailingEmpty = (7 - (count($cells) % 7)) % 7;
        for ($i = 0; $i < $trailingEmpty; $i++) {
            $cells[] = [
                'date' => null,
                'day' => null,
                'isToday' => false,
                'isSelected' => false,
            ];
        }

        return $cells;
    }

    private function buildPreview(?Season $season, Rollout $rollout): ?RolloutPreview
    {
        if ($season === null || $this->selectedDates === [] || $this->intervals === []) {
            return null;
        }

        $normalizedIntervals = [];
        foreach ($this->intervals as $interval) {
            $start = trim((string) ($interval['start'] ?? ''));
            $end = trim((string) ($interval['end'] ?? ''));
            $capacity = (int) ($interval['capacity'] ?? 0);

            if ($start === '' || $end === '' || $start === $end || $capacity < 1) {
                return null;
            }

            $normalizedIntervals[] = [
                'start' => $start,
                'end' => $end,
                'capacity' => $capacity,
                'isAdminOnly' => (bool) ($interval['isAdminOnly'] ?? false),
            ];
        }

        try {
            return $rollout->preview($season, $this->selectedDates, $normalizedIntervals);
        } catch (ValidationException) {
            return null;
        }
    }

    public function render(Rollout $rollout): View
    {
        $season = $this->currentSeason();
        $timezone = $this->team->timezone;
        $monthStart = CarbonImmutable::create($this->calendarYear, $this->calendarMonth, 1, 0, 0, 0, $timezone);
        $preview = $this->buildPreview($season, $rollout);

        return $this->view([
            'team' => $this->team,
            'season' => $season,
            'timezone' => $timezone,
            'monthLabel' => $monthStart->translatedFormat('F Y'),
            'calendarCells' => $this->buildCalendarCells($timezone),
            'weekdays' => [
                __('Mon'),
                __('Tue'),
                __('Wed'),
                __('Thu'),
                __('Fri'),
                __('Sat'),
                __('Sun'),
            ],
            'preview' => $preview,
        ])->title(__('Roll out slots'));
    }
}; ?>

<section class="max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Roll out slots') }}</flux:heading>
            <flux:text class="mt-1">
                {{ $team->name }}@if ($season !== null) · {{ $season->name }}@endif
            </flux:text>
        </div>

        <flux:button variant="ghost" icon="arrow-left" :href="route('schedule.index')" wire:navigate>
            {{ __('Back to schedule') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Dates') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Pick the dates for this rollout.') }}</flux:text>
                </div>

                <div class="flex items-center gap-2">
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="chevron-left"
                        wire:click="previousMonth"
                        data-test="calendar-prev-month"
                        aria-label="{{ __('Previous month') }}"
                    />
                    <span class="min-w-36 text-center text-sm font-medium capitalize text-zinc-900 dark:text-white" data-test="calendar-month-label">
                        {{ $monthLabel }}
                    </span>
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="chevron-right"
                        wire:click="nextMonth"
                        data-test="calendar-next-month"
                        aria-label="{{ __('Next month') }}"
                    />
                </div>
            </div>

            <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400">
                @foreach ($weekdays as $weekday)
                    <div wire:key="weekday-{{ $loop->index }}" class="py-1">{{ $weekday }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7 gap-1">
                @foreach ($calendarCells as $cell)
                    @if ($cell['date'] === null)
                        <div wire:key="empty-{{ $loop->index }}" class="h-10"></div>
                    @else
                        <button
                            type="button"
                            wire:key="day-{{ $cell['date'] }}"
                            wire:click="toggleDate('{{ $cell['date'] }}')"
                            data-test="calendar-day-{{ $cell['date'] }}"
                            aria-pressed="{{ $cell['isSelected'] ? 'true' : 'false' }}"
                            @class([
                                'flex h-10 w-full cursor-pointer items-center justify-center rounded-lg text-sm font-medium transition',
                                'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $cell['isSelected'],
                                'bg-zinc-100 text-zinc-900 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700' => ! $cell['isSelected'] && $cell['isToday'],
                                'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800' => ! $cell['isSelected'] && ! $cell['isToday'],
                            ])
                        >
                            {{ $cell['day'] }}
                        </button>
                    @endif
                @endforeach
            </div>

            @if ($selectedDates !== [])
                <div class="flex flex-wrap items-center gap-2 pt-2">
                    @foreach ($selectedDates as $selectedDate)
                        <flux:badge wire:key="selected-{{ $selectedDate }}" size="sm" color="blue">
                            <span>{{ $selectedDate }}</span>
                            <button
                                type="button"
                                wire:click="toggleDate('{{ $selectedDate }}')"
                                class="ms-1 cursor-pointer opacity-75 hover:opacity-100"
                                aria-label="{{ __('Remove') }} {{ $selectedDate }}"
                            >
                                &times;
                            </button>
                        </flux:badge>
                    @endforeach

                    <flux:button type="button" variant="ghost" size="xs" wire:click="clearDates">
                        {{ __('Clear dates') }}
                    </flux:button>
                </div>
            @endif

            <flux:error name="selectedDates" />
        </flux:card>

        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Intervals') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Times are in :timezone.', ['timezone' => $timezone]) }}</flux:text>
                </div>

                <flux:button
                    type="button"
                    variant="ghost"
                    size="sm"
                    icon="plus"
                    wire:click="addInterval"
                    data-test="add-interval"
                >
                    {{ __('Add interval') }}
                </flux:button>
            </div>

            <div class="space-y-4">
                @foreach ($intervals as $index => $interval)
                    @php
                        $isOvernightInterval = ($interval['start'] ?? '') !== ''
                            && ($interval['end'] ?? '') !== ''
                            && $interval['end'] < $interval['start'];
                    @endphp

                    <div
                        wire:key="interval-{{ $index }}"
                        class="grid grid-cols-1 items-start gap-4 rounded-lg border border-zinc-200 p-4 sm:grid-cols-12 dark:border-zinc-700"
                    >
                        <div class="sm:col-span-4">
                            <flux:field>
                                <flux:label>{{ __('Start time') }}</flux:label>
                                <flux:input
                                    type="time"
                                    wire:model.live="intervals.{{ $index }}.start"
                                    data-test="interval-{{ $index }}-start"
                                    required
                                />
                                <flux:error name="intervals.{{ $index }}.start" />
                            </flux:field>
                        </div>

                        <div class="sm:col-span-4">
                            <flux:field>
                                <flux:label>{{ __('End time') }}</flux:label>
                                <flux:input
                                    type="time"
                                    wire:model.live="intervals.{{ $index }}.end"
                                    data-test="interval-{{ $index }}-end"
                                    required
                                />
                                <flux:error name="intervals.{{ $index }}.end" />
                                @if ($isOvernightInterval)
                                    <flux:text class="mt-1 text-xs">{{ __('Ends next day') }}</flux:text>
                                @endif
                            </flux:field>
                        </div>

                        <div class="sm:col-span-3">
                            <flux:field>
                                <flux:label>{{ __('Capacity') }}</flux:label>
                                <flux:input
                                    type="number"
                                    min="1"
                                    wire:model.live="intervals.{{ $index }}.capacity"
                                    data-test="interval-{{ $index }}-capacity"
                                    required
                                />
                                <flux:error name="intervals.{{ $index }}.capacity" />
                            </flux:field>
                        </div>

                        <div class="flex justify-end sm:col-span-1 sm:pt-7">
                            @if (count($intervals) > 1)
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="removeInterval({{ $index }})"
                                    data-test="remove-interval-{{ $index }}"
                                    aria-label="{{ __('Remove interval') }}"
                                />
                            @endif
                        </div>

                        <div class="sm:col-span-12">
                            <flux:checkbox
                                wire:model.live="intervals.{{ $index }}.isAdminOnly"
                                :label="__('Admin-only')"
                                data-test="interval-{{ $index }}-admin-only"
                            />
                            <flux:error name="intervals.{{ $index }}.isAdminOnly" />
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:error name="intervals" />
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Preview') }}</flux:heading>

            @if ($preview === null)
                <flux:text>{{ __('Pick at least one date and valid intervals to preview.') }}</flux:text>
            @else
                <flux:text class="font-medium text-zinc-900 dark:text-white" data-test="rollout-preview-summary">
                    {{ $preview->summary() }}
                </flux:text>

                @if ($preview->hasDuplicates())
                    <flux:callout icon="exclamation-triangle" color="amber" role="alert" data-test="rollout-duplicate-warning">
                        <flux:callout.text>
                            {{ __('Some planned slots have the exact same start and end time as existing slots. You can still save anyway.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($preview->plannedSlots as $plannedSlot)
                        <div
                            wire:key="preview-{{ $plannedSlot['date'] }}-{{ $plannedSlot['intervalIndex'] }}"
                            class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $plannedSlot['date'] }}</span>
                                <span class="text-zinc-600 dark:text-zinc-300">
                                    {{ $plannedSlot['startTime'] }}–{{ $plannedSlot['endTime'] }}
                                </span>
                                @if ($plannedSlot['endsNextDay'])
                                    <flux:badge size="sm" color="zinc">{{ __('Ends next day') }}</flux:badge>
                                @endif
                                @if ($plannedSlot['isAdminOnly'])
                                    <flux:badge size="sm" color="purple">{{ __('Admin-only') }}</flux:badge>
                                @endif
                                @if ($plannedSlot['isDuplicate'])
                                    <flux:badge size="sm" color="amber">{{ __('Already exists') }}</flux:badge>
                                @endif
                            </div>

                            <flux:text class="text-xs">
                                {{ __('Capacity') }}: {{ $plannedSlot['capacity'] }}
                            </flux:text>
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="route('schedule.index')" wire:navigate>
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary" data-test="rollout-save">
                {{ __('Save rollout') }}
            </flux:button>
        </div>
    </form>
</section>
