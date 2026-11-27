<?php

use App\Auth\CurrentTeam;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\HourGoals;
use App\Scheduling\MemberHours;
use App\Scheduling\Shifts;
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

        $this->team->loadMissing('currentSeason');
        $season = $this->team->currentSeason;

        $shifts = ($membership === null || $season === null)
            ? collect()
            : $membership->shifts()
                ->whereRelation('slot', 'season_id', $season->id)
                ->with('slot')
                ->get();

        $goalHours = $season !== null && $membership !== null
            ? HourGoals::effectiveForMember($this->team->settings, $season, $membership)
            : null;

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
            'goalHours' => $goalHours,
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

    <x-hour-goal-progress :summary="$hoursSummary" :goal-hours="$goalHours" />

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
                    <x-my-shifts.shift-card
                        :shift="$shift"
                        :team="$team"
                        :timezone="$timezone"
                        :member-hours="$memberHours"
                        :now="$now"
                        section="upcoming"
                    />
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
                    <x-my-shifts.shift-card
                        :shift="$shift"
                        :team="$team"
                        :timezone="$timezone"
                        :member-hours="$memberHours"
                        :now="$now"
                        section="past"
                    />
                @endforeach
            </div>
        @endif
    </div>
</section>
