<?php

use App\Auth\CurrentTeam;
use App\Enums\AttendanceStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Season;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\HoursOverview;
use App\Scheduling\MemberHours;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $teamId;

    public string $fromDate = '';

    public string $toDate = '';

    public string $status = 'all';

    public string $search = '';

    public ?int $expandedMembershipId = null;

    protected Team $team;

    public function mount(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        Gate::authorize('viewHoursOverview', $team);

        $this->team = $team;
        $this->teamId = $team->id;
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);
        Gate::authorize('viewHoursOverview', $team);

        $this->team = $team;
    }

    public function toggleMember(int $membershipId): void
    {
        $this->expandedMembershipId = $this->expandedMembershipId === $membershipId
            ? null
            : $membershipId;
    }

    public function clearFilters(): void
    {
        $this->fromDate = '';
        $this->toDate = '';
        $this->status = 'all';
        $this->search = '';
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

    public function render(HoursOverview $hoursOverview, MemberHours $memberHours): View
    {
        $season = $this->currentSeason();
        $normalizedStatus = in_array($this->status, ['all', 'active', 'removed'], true)
            ? $this->status
            : 'all';

        $rows = $season !== null
            ? $hoursOverview->forTeam(
                team: $this->team,
                season: $season,
                fromDate: $this->fromDate !== '' ? $this->fromDate : null,
                toDate: $this->toDate !== '' ? $this->toDate : null,
                status: $normalizedStatus,
                search: $this->search,
            )
            : collect();

        $hasActiveFilters = $this->fromDate !== ''
            || $this->toDate !== ''
            || $normalizedStatus !== 'all'
            || trim($this->search) !== '';

        return $this->view([
            'team' => $this->team,
            'season' => $season,
            'timezone' => $this->team->timezone,
            'rows' => $rows,
            'memberHours' => $memberHours,
            'now' => now()->toImmutable(),
            'hasActiveFilters' => $hasActiveFilters,
        ])->title(__('Hours overview'));
    }
}; ?>

<section class="max-w-4xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Hours overview') }}</flux:heading>
        <flux:text class="mt-1">
            {{ $team->name }}@if ($season !== null) · {{ $season->name }}@endif
        </flux:text>
    </div>

    <flux:card class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:field>
                <flux:label>{{ __('Search') }}</flux:label>
                <flux:input
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    :placeholder="__('Search by member name')"
                    data-test="hours-search"
                />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Membership status') }}</flux:label>
                <flux:select wire:model.live="status" data-test="hours-status-filter">
                    <option value="all">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="removed">{{ __('Removed') }}</option>
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('From') }}</flux:label>
                <flux:input
                    type="date"
                    wire:model.live="fromDate"
                    data-test="hours-from-date"
                />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('To') }}</flux:label>
                <flux:input
                    type="date"
                    wire:model.live="toDate"
                    data-test="hours-to-date"
                />
            </flux:field>
        </div>

        @if ($hasActiveFilters)
            <div class="flex justify-end">
                <flux:button
                    variant="ghost"
                    size="sm"
                    wire:click="clearFilters"
                    data-test="hours-clear-filters"
                >
                    {{ __('Clear filters') }}
                </flux:button>
            </div>
        @endif
    </flux:card>

    @if ($rows->isEmpty())
        <flux:card data-test="hours-empty-state">
            <flux:text>{{ __('No members match the current filters.') }}</flux:text>
        </flux:card>
    @else
        <div class="space-y-3">
            @foreach ($rows as $row)
                @php
                    $membership = $row['membership'];
                    $summary = $row['summary'];
                    $shifts = $row['shifts'];
                    $isExpanded = $expandedMembershipId === $membership->id;
                @endphp

                <flux:card
                    wire:key="hours-member-{{ $membership->id }}"
                    data-test="hours-member-{{ $membership->id }}"
                    class="space-y-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <flux:avatar
                                size="sm"
                                :name="$membership->user->name"
                                :src="$membership->user->profilePictureUrl()"
                                :initials="$membership->user->initials()"
                            />

                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-zinc-900 dark:text-white">
                                        {{ $membership->user->name }}
                                    </span>

                                    @if ($membership->status === MembershipStatus::Removed)
                                        <flux:badge size="sm" color="red" data-test="removed-badge-{{ $membership->id }}">
                                            {{ __('Removed') }}
                                        </flux:badge>
                                    @elseif ($membership->role === MembershipRole::Admin)
                                        <flux:badge size="sm" color="amber">
                                            {{ __('Admin') }}
                                        </flux:badge>
                                    @else
                                        <flux:badge size="sm">
                                            {{ __('Volunteer') }}
                                        </flux:badge>
                                    @endif
                                </div>

                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $membership->user->email }}
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-4">
                            <div class="text-right">
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('Planned hours') }}
                                </div>
                                <div class="font-medium text-zinc-900 dark:text-white" data-test="planned-hours-{{ $membership->id }}">
                                    {{ $summary->plannedFormatted() }}
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('Worked hours') }}
                                </div>
                                <div class="font-medium text-zinc-900 dark:text-white" data-test="worked-hours-{{ $membership->id }}">
                                    {{ $summary->workedFormatted() }}
                                </div>
                            </div>

                            <flux:button
                                variant="ghost"
                                size="sm"
                                :icon-trailing="$isExpanded ? 'chevron-up' : 'chevron-down'"
                                wire:click="toggleMember({{ $membership->id }})"
                                data-test="toggle-member-{{ $membership->id }}"
                            >
                                {{ $isExpanded ? __('Hide shifts') : __('Shift history') }}
                            </flux:button>
                        </div>
                    </div>

                    @if ($isExpanded)
                        <div
                            class="border-t border-zinc-200 pt-4 dark:border-zinc-700 space-y-2"
                            data-test="member-history-{{ $membership->id }}"
                        >
                            @if ($shifts->isEmpty())
                                <flux:text class="text-sm">{{ __('No confirmed shifts in this period.') }}</flux:text>
                            @else
                                @foreach ($shifts as $shift)
                                    @php
                                        $startsLocal = $shift->slot->starts_at->setTimezone($timezone);
                                        $endsLocal = $shift->slot->ends_at->setTimezone($timezone);
                                        $isOvernight = ! $startsLocal->isSameDay($endsLocal);
                                        $actualStartsLocal = $shift->actual_starts_at?->setTimezone($timezone);
                                        $actualEndsLocal = $shift->actual_ends_at?->setTimezone($timezone);
                                        $recordedLocal = $shift->attendance_recorded_at?->setTimezone($timezone);
                                        $workedMinutes = $memberHours->workedMinutesForShift($shift, $now);
                                        $plannedMinutes = $memberHours->plannedMinutesForShift($shift);
                                    @endphp

                                    <div
                                        wire:key="history-shift-{{ $shift->id }}"
                                        data-test="history-shift-{{ $shift->id }}"
                                        class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                                    >
                                        <div class="space-y-1">
                                            <div class="font-medium text-zinc-900 dark:text-white capitalize">
                                                <a
                                                    href="{{ route('schedule.slots.show', $shift->slot) }}"
                                                    wire:navigate
                                                    class="hover:underline"
                                                >
                                                    {{ $startsLocal->translatedFormat('l j. F Y') }}
                                                </a>
                                            </div>

                                            <flux:text class="text-sm">
                                                {{ __('Planned') }}: {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }}
                                                @if ($isOvernight)
                                                    ({{ __('Ends next day') }})
                                                @endif
                                            </flux:text>

                                            @if ($shift->attendance_status === AttendanceStatus::Adjusted && $actualStartsLocal !== null && $actualEndsLocal !== null)
                                                <flux:text class="text-sm" data-test="history-actual-{{ $shift->id }}">
                                                    {{ __('Actual') }}: {{ $actualStartsLocal->format('H:i') }}–{{ $actualEndsLocal->format('H:i') }}
                                                </flux:text>
                                            @endif

                                            @if ($shift->hasAttendanceOverride() && $recordedLocal !== null)
                                                <flux:text class="text-xs" data-test="history-audit-{{ $shift->id }}">
                                                    @if ($shift->attendanceRecordedBy !== null)
                                                        {{ __('Edited by :name on :time', ['name' => $shift->attendanceRecordedBy->name, 'time' => $recordedLocal->translatedFormat('j. M Y H:i')]) }}
                                                    @else
                                                        {{ __('Edited on :time', ['time' => $recordedLocal->translatedFormat('j. M Y H:i')]) }}
                                                    @endif
                                                </flux:text>
                                            @endif
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($shift->attendance_status === AttendanceStatus::Adjusted)
                                                <flux:badge size="sm" color="blue" data-test="history-status-{{ $shift->id }}">
                                                    {{ __('Adjusted') }}
                                                </flux:badge>
                                            @elseif ($shift->attendance_status === AttendanceStatus::NoShow)
                                                <flux:badge size="sm" color="red" data-test="history-status-{{ $shift->id }}">
                                                    {{ __('No-show') }}
                                                </flux:badge>
                                            @elseif ($workedMinutes === null)
                                                <flux:badge size="sm" color="zinc" data-test="history-status-{{ $shift->id }}">
                                                    {{ __('Upcoming') }}
                                                </flux:badge>
                                            @else
                                                <flux:badge size="sm" color="green" data-test="history-status-{{ $shift->id }}">
                                                    {{ __('Planned') }}
                                                </flux:badge>
                                            @endif

                                            <span class="text-sm font-medium text-zinc-900 dark:text-white" data-test="history-duration-{{ $shift->id }}">
                                                @if ($workedMinutes !== null)
                                                    {{ MemberHours::formatDuration($workedMinutes) }}
                                                @else
                                                    {{ MemberHours::formatDuration($plannedMinutes) }}
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>
    @endif
</section>
