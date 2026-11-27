<?php

use App\Auth\CurrentTeam;
use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\MemberHours;
use App\Scheduling\Shifts;
use Illuminate\Database\Eloquent\Builder;
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

    public function cancelShift(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;

        $shift = Shift::query()->with(['membership', 'slot.season'])->findOrFail($shiftId);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('cancel', $shift);

        $shifts->cancel($shift, $this->user());

        $this->statusMessage = __('Your shift has been cancelled.');
    }

    public function withdrawShift(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;

        $shift = Shift::query()->with(['membership', 'slot.season'])->findOrFail($shiftId);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('withdraw', $shift);

        $shifts->withdraw($shift, $this->user());

        $this->statusMessage = __('Your shift request has been withdrawn.');
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        return $user;
    }

    public function render(MemberHours $memberHours): View
    {
        $user = $this->user();
        $timezone = $this->team->timezone;
        $now = now()->toImmutable();

        $membership = $user->activeMemberships()
            ->whereBelongsTo($this->team)
            ->first();

        $currentSeasonId = $this->team->current_season_id;

        $shifts = ($membership === null || $currentSeasonId === null)
            ? collect()
            : $membership->shifts()
                ->whereHas('slot', function (Builder $query) use ($currentSeasonId): void {
                    $query->where('season_id', $currentSeasonId);
                })
                ->with('slot')
                ->get();

        $hoursSummary = $memberHours->summarize($shifts, $now);

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
            'hoursSummary' => $hoursSummary,
            'memberHours' => $memberHours,
            'now' => $now,
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

    <div class="grid gap-4 sm:grid-cols-2" data-test="my-shifts-hours-summary">
        <flux:card>
            <flux:text class="text-sm">{{ __('Planned hours') }}</flux:text>
            <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="my-planned-hours">
                {{ $hoursSummary->plannedFormatted() }}
            </div>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm">{{ __('Worked hours') }}</flux:text>
            <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="my-worked-hours">
                {{ $hoursSummary->workedFormatted() }}
            </div>
        </flux:card>
    </div>

    @if ($statusMessage !== null)
        <flux:callout icon="check-circle" color="green" role="status">
            <flux:callout.text>{{ $statusMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($errors->has('shift'))
        <flux:callout icon="exclamation-circle" color="red" role="alert">
            <flux:callout.text>{{ $errors->first('shift') }}</flux:callout.text>
        </flux:callout>
    @endif

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
                        $plannedDuration = MemberHours::formatDuration($memberHours->plannedMinutesForShift($shift));
                        $isAdjusted = $shift->status === ShiftStatus::Confirmed
                            && $shift->attendance_status === AttendanceStatus::Adjusted
                            && $shift->actual_starts_at !== null
                            && $shift->actual_ends_at !== null;
                        $isNoShow = $shift->status === ShiftStatus::Confirmed
                            && $shift->attendance_status === AttendanceStatus::NoShow;
                        $actualStartsLocal = $isAdjusted ? $shift->actual_starts_at->setTimezone($timezone) : null;
                        $actualEndsLocal = $isAdjusted ? $shift->actual_ends_at->setTimezone($timezone) : null;
                        $workedMinutes = $isAdjusted ? $memberHours->workedMinutesForShift($shift, $now) : null;
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
                                    {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }} · {{ $plannedDuration }}
                                </flux:text>
                                @if ($isAdjusted && $actualStartsLocal !== null && $actualEndsLocal !== null && $workedMinutes !== null)
                                    <flux:text class="mt-0.5 text-sm" data-test="shift-actual-{{ $shift->id }}">
                                        {{ __('Actual') }}: {{ $actualStartsLocal->format('H:i') }}–{{ $actualEndsLocal->format('H:i') }} · {{ MemberHours::formatDuration($workedMinutes) }}
                                        @if (! $actualStartsLocal->isSameDay($actualEndsLocal))
                                            ({{ __('Ends next day') }})
                                        @endif
                                    </flux:text>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" :color="$badgeColor">
                                    {{ $shift->status->label() }}
                                </flux:badge>

                                @if ($isAdjusted)
                                    <flux:badge size="sm" color="blue" data-test="shift-attendance-{{ $shift->id }}">
                                        {{ __('Adjusted') }}
                                    </flux:badge>
                                @elseif ($isNoShow)
                                    <flux:badge size="sm" color="red" data-test="shift-attendance-{{ $shift->id }}">
                                        {{ __('No-show') }}
                                    </flux:badge>
                                @endif

                                @if ($shift->status === ShiftStatus::Confirmed)
                                    @if ($shift->slot->isBeforeCancellationDeadline($team))
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            wire:click="cancelShift({{ $shift->id }})"
                                            data-test="cancel-shift-{{ $shift->id }}"
                                        >
                                            {{ __('Cancel shift') }}
                                        </flux:button>
                                    @else
                                        <flux:text class="text-xs" data-test="contact-admin-to-cancel-shift-{{ $shift->id }}">
                                            {{ __('Contact an admin to cancel.') }}
                                        </flux:text>
                                    @endif
                                @elseif ($shift->status === ShiftStatus::Pending)
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        wire:click="withdrawShift({{ $shift->id }})"
                                        data-test="withdraw-shift-{{ $shift->id }}"
                                    >
                                        {{ __('Withdraw') }}
                                    </flux:button>
                                @endif
                            </div>
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
                        $plannedDuration = MemberHours::formatDuration($memberHours->plannedMinutesForShift($shift));
                        $isAdjusted = $shift->status === ShiftStatus::Confirmed
                            && $shift->attendance_status === AttendanceStatus::Adjusted
                            && $shift->actual_starts_at !== null
                            && $shift->actual_ends_at !== null;
                        $isNoShow = $shift->status === ShiftStatus::Confirmed
                            && $shift->attendance_status === AttendanceStatus::NoShow;
                        $actualStartsLocal = $isAdjusted ? $shift->actual_starts_at->setTimezone($timezone) : null;
                        $actualEndsLocal = $isAdjusted ? $shift->actual_ends_at->setTimezone($timezone) : null;
                        $workedMinutes = $isAdjusted ? $memberHours->workedMinutesForShift($shift, $now) : null;
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
                                    {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }} · {{ $plannedDuration }}
                                </flux:text>
                                @if ($isAdjusted && $actualStartsLocal !== null && $actualEndsLocal !== null && $workedMinutes !== null)
                                    <flux:text class="mt-0.5 text-sm" data-test="shift-actual-{{ $shift->id }}">
                                        {{ __('Actual') }}: {{ $actualStartsLocal->format('H:i') }}–{{ $actualEndsLocal->format('H:i') }} · {{ MemberHours::formatDuration($workedMinutes) }}
                                        @if (! $actualStartsLocal->isSameDay($actualEndsLocal))
                                            ({{ __('Ends next day') }})
                                        @endif
                                    </flux:text>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" :color="$badgeColor">
                                    {{ $shift->status->label() }}
                                </flux:badge>

                                @if ($isAdjusted)
                                    <flux:badge size="sm" color="blue" data-test="shift-attendance-{{ $shift->id }}">
                                        {{ __('Adjusted') }}
                                    </flux:badge>
                                @elseif ($isNoShow)
                                    <flux:badge size="sm" color="red" data-test="shift-attendance-{{ $shift->id }}">
                                        {{ __('No-show') }}
                                    </flux:badge>
                                @endif

                                @if ($shift->status === ShiftStatus::Pending)
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        wire:click="withdrawShift({{ $shift->id }})"
                                        data-test="withdraw-shift-{{ $shift->id }}"
                                    >
                                        {{ __('Withdraw') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>
</section>
