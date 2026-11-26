<?php

use App\Auth\CurrentTeam;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
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
    public int $slotId;

    #[Locked]
    public ?string $statusMessage = null;

    protected Team $team;

    protected Slot $slot;

    public function mount(Slot $slot, CurrentTeam $currentTeam): void
    {
        $slot->loadMissing('season');

        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        abort_unless($slot->season->team_id === $team->id, 403);
        Gate::authorize('manage', $slot);

        $this->team = $team;
        $this->teamId = $team->id;
        $this->slot = $slot;
        $this->slotId = $slot->id;
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);

        $slot = Slot::query()->with('season')->findOrFail($this->slotId);
        abort_unless($slot->season->team_id === $this->teamId, 403);
        Gate::authorize('manage', $slot);

        $this->team = $team;
        $this->slot = $slot;
    }

    public function confirmShift(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;

        $shift = Shift::query()->with('membership')->findOrFail($shiftId);
        abort_unless($shift->slot_id === $this->slotId, 403);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('confirm', $shift);

        $shifts->confirm($shift, $this->user());

        $this->statusMessage = __('Shift confirmed.');
    }

    public function rejectShift(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;

        $shift = Shift::query()->with('membership')->findOrFail($shiftId);
        abort_unless($shift->slot_id === $this->slotId, 403);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('reject', $shift);

        $shifts->reject($shift, $this->user());

        $this->statusMessage = __('Shift rejected.');
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

        $confirmedShifts = $this->slot->confirmedShifts()
            ->with('membership.user')
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get();

        $pendingShifts = $this->slot->pendingShifts()
            ->with('membership.user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $startsLocal = $this->slot->starts_at->setTimezone($timezone);
        $endsLocal = $this->slot->ends_at->setTimezone($timezone);

        return $this->view([
            'team' => $this->team,
            'currentSlot' => $this->slot,
            'startsLocal' => $startsLocal,
            'endsLocal' => $endsLocal,
            'isOvernight' => ! $startsLocal->isSameDay($endsLocal),
            'confirmedShifts' => $confirmedShifts,
            'pendingShifts' => $pendingShifts,
        ])->title(__('Slot details'));
    }
}; ?>

<section class="max-w-4xl space-y-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('schedule.index')" wire:navigate>
            {{ __('Back to schedule') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="capitalize">
                {{ $startsLocal->translatedFormat('l j. F Y') }}
            </flux:heading>
            <flux:text class="mt-1">
                {{ $startsLocal->format('H:i') }}–{{ $endsLocal->format('H:i') }}
                @if ($isOvernight) · {{ __('Ends next day') }}@endif
                · {{ $team->name }}
            </flux:text>
        </div>

        <flux:badge size="sm" :color="$confirmedShifts->count() >= $currentSlot->capacity ? 'zinc' : 'blue'">
            {{ $confirmedShifts->count() }} / {{ $currentSlot->capacity }}
        </flux:badge>
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
        <flux:heading size="lg" level="2">{{ __('Pending shifts') }}</flux:heading>

        @if ($pendingShifts->isEmpty())
            <flux:card>
                <flux:text>{{ __('No pending shifts.') }}</flux:text>
            </flux:card>
        @else
            <div class="space-y-2">
                @foreach ($pendingShifts as $shift)
                    <flux:card wire:key="pending-shift-{{ $shift->id }}" data-test="pending-shift-{{ $shift->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $shift->membership->user->name }}
                                </div>
                                <flux:text class="text-sm">{{ $shift->membership->user->email }}</flux:text>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:button
                                    variant="primary"
                                    size="sm"
                                    wire:click="confirmShift({{ $shift->id }})"
                                    data-test="confirm-shift-{{ $shift->id }}"
                                >
                                    {{ __('Confirm') }}
                                </flux:button>
                                <flux:button
                                    variant="danger"
                                    size="sm"
                                    wire:click="rejectShift({{ $shift->id }})"
                                    data-test="reject-shift-{{ $shift->id }}"
                                >
                                    {{ __('Reject') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Confirmed shifts') }}</flux:heading>

        @if ($confirmedShifts->isEmpty())
            <flux:card>
                <flux:text>{{ __('No confirmed shifts yet.') }}</flux:text>
            </flux:card>
        @else
            <div class="space-y-2">
                @foreach ($confirmedShifts as $shift)
                    <flux:card wire:key="confirmed-shift-{{ $shift->id }}" data-test="confirmed-shift-{{ $shift->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $shift->membership->user->name }}
                                </div>
                                <flux:text class="text-sm">{{ $shift->membership->user->email }}</flux:text>
                            </div>

                            <flux:badge size="sm" color="green">
                                {{ __('Confirmed') }}
                            </flux:badge>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>
</section>
