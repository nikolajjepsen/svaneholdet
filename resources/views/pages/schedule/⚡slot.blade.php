<?php

use App\Auth\CurrentTeam;
use App\Models\Membership;
use App\Models\Shift;
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
    public int $slotId;

    #[Locked]
    public ?string $statusMessage = null;

    public ?int $assignMembershipId = null;

    public int $capacity = 1;

    public bool $showOverCapacityModal = false;

    #[Locked]
    public ?string $overCapacityAction = null;

    #[Locked]
    public ?int $overCapacityValue = null;

    /** @var list<string> */
    #[Locked]
    public array $overCapacityWarnings = [];

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
        $this->capacity = $slot->capacity;
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

    public function assignMember(Shifts $shifts): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        $validated = $this->validate([
            'assignMembershipId' => ['required', 'integer'],
        ]);

        $this->performAssign((int) $validated['assignMembershipId'], $shifts, acceptWarnings: false);
    }

    public function updateCapacity(Slots $slots): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        Gate::authorize('update', $this->slot);

        $validated = $this->validate([
            'capacity' => ['required', 'integer', 'min:1'],
        ]);

        $this->performUpdateCapacity((int) $validated['capacity'], $slots, acceptWarnings: false);
    }

    public function confirmShift(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        $this->performConfirm($shiftId, $shifts, acceptWarnings: false);
    }

    public function rejectShift(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        $shift = Shift::query()->with('membership')->findOrFail($shiftId);
        abort_unless($shift->slot_id === $this->slotId, 403);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('reject', $shift);

        $shifts->reject($shift, $this->user());

        $this->statusMessage = __('Shift rejected.');
    }

    public function cancelShift(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        $shift = Shift::query()->with('membership')->findOrFail($shiftId);
        abort_unless($shift->slot_id === $this->slotId, 403);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('cancelByAdmin', $shift);

        $shifts->cancelByAdmin($shift, $this->user());

        $this->statusMessage = __('Shift cancelled.');
    }

    public function acceptOverCapacityWarning(Shifts $shifts, Slots $slots): void
    {
        $this->statusMessage = null;

        $action = $this->overCapacityAction;
        $value = $this->overCapacityValue;

        $this->dismissOverCapacityWarning();

        if ($action === null || $value === null) {
            return;
        }

        match ($action) {
            'assign' => $this->performAssign($value, $shifts, acceptWarnings: true),
            'confirm' => $this->performConfirm($value, $shifts, acceptWarnings: true),
            'capacity' => $this->performUpdateCapacity($value, $slots, acceptWarnings: true),
            default => null,
        };
    }

    public function dismissOverCapacityWarning(): void
    {
        $this->showOverCapacityModal = false;
        $this->overCapacityAction = null;
        $this->overCapacityValue = null;
        $this->overCapacityWarnings = [];
    }

    private function performAssign(int $membershipId, Shifts $shifts, bool $acceptWarnings): void
    {
        $membership = Membership::query()->findOrFail($membershipId);
        abort_unless($membership->team_id === $this->teamId, 403);
        Gate::authorize('assign', $this->slot);

        $result = $shifts->assign($this->slot, $membership, $this->user(), acceptWarnings: $acceptWarnings);

        if ($result->hasWarnings()) {
            $this->openOverCapacityModal('assign', $membership->id, $result->warnings);

            return;
        }

        $this->assignMembershipId = null;
        $this->statusMessage = __('Member assigned to slot.');
    }

    private function performConfirm(int $shiftId, Shifts $shifts, bool $acceptWarnings): void
    {
        $shift = Shift::query()->with('membership')->findOrFail($shiftId);
        abort_unless($shift->slot_id === $this->slotId, 403);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('confirm', $shift);

        $result = $shifts->confirm($shift, $this->user(), acceptWarnings: $acceptWarnings);

        if ($result->hasWarnings()) {
            $this->openOverCapacityModal('confirm', $shift->id, $result->warnings);

            return;
        }

        $this->statusMessage = __('Shift confirmed.');
    }

    private function performUpdateCapacity(int $newCapacity, Slots $slots, bool $acceptWarnings): void
    {
        Gate::authorize('update', $this->slot);

        $result = $slots->updateCapacity($this->slot, $this->user(), $newCapacity, acceptWarnings: $acceptWarnings);

        if ($result->hasWarnings()) {
            $this->openOverCapacityModal('capacity', $newCapacity, $result->warnings);

            return;
        }

        if ($result->slot !== null) {
            $this->slot = $result->slot;
            $this->capacity = $result->slot->capacity;
        }

        $this->statusMessage = __('Capacity updated.');
    }

    /**
     * @param  list<string>  $warnings
     */
    private function openOverCapacityModal(string $action, int $value, array $warnings): void
    {
        $this->overCapacityAction = $action;
        $this->overCapacityValue = $value;
        $this->overCapacityWarnings = $warnings;
        $this->showOverCapacityModal = true;
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
            ->get()
            ->values();

        $pendingShifts = $this->slot->pendingShifts()
            ->with('membership.user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values();

        $takenMembershipIds = $confirmedShifts
            ->pluck('membership_id')
            ->merge($pendingShifts->pluck('membership_id'))
            ->unique();

        $assignableMemberships = $this->team->activeMemberships()
            ->whereNotIn('id', $takenMembershipIds)
            ->with('user')
            ->get()
            ->sortBy(fn (Membership $membership): string => mb_strtolower($membership->user->name))
            ->values();

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
            'assignableMemberships' => $assignableMemberships,
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

        @php
            $confirmedCount = $confirmedShifts->count();
            $capacityBadgeColor = $confirmedCount > $currentSlot->capacity
                ? 'amber'
                : ($confirmedCount === $currentSlot->capacity ? 'zinc' : 'blue');
        @endphp

        <flux:badge size="sm" :color="$capacityBadgeColor" data-test="slot-capacity-badge">
            {{ $confirmedCount }} / {{ $currentSlot->capacity }}
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

    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading size="md" level="2">{{ __('Assign member') }}</flux:heading>

            @if ($assignableMemberships->isEmpty())
                <flux:text>{{ __('All active members are already on this slot.') }}</flux:text>
            @else
                <form wire:submit="assignMember" class="space-y-4">
                    <flux:field>
                        <flux:label>{{ __('Member') }}</flux:label>
                        <flux:select wire:model="assignMembershipId" name="assignMembershipId" data-test="assign-member-select">
                            <flux:select.option value="">{{ __('Choose a member') }}</flux:select.option>
                            @foreach ($assignableMemberships as $membership)
                                <flux:select.option wire:key="assignable-member-{{ $membership->id }}" value="{{ $membership->id }}">
                                    {{ $membership->user->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="assignMembershipId" />
                    </flux:field>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" size="sm" data-test="assign-member-submit">
                            {{ __('Assign') }}
                        </flux:button>
                    </div>
                </form>
            @endif
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="md" level="2">{{ __('Slot capacity') }}</flux:heading>

            <form wire:submit="updateCapacity" class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Capacity') }}</flux:label>
                    <flux:input type="number" min="1" name="capacity" wire:model="capacity" data-test="slot-capacity-input" required />
                    <flux:error name="capacity" />
                </flux:field>

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" size="sm" data-test="save-capacity-submit">
                        {{ __('Save capacity') }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    </div>

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
                @foreach ($confirmedShifts as $index => $shift)
                    <flux:card wire:key="confirmed-shift-{{ $shift->id }}" data-test="confirmed-shift-{{ $shift->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $shift->membership->user->name }}
                                </div>
                                <flux:text class="text-sm">{{ $shift->membership->user->email }}</flux:text>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                @if ($index >= $currentSlot->capacity)
                                    <flux:badge size="sm" color="amber" data-test="over-capacity-badge-{{ $shift->id }}">
                                        {{ __('Over capacity') }}
                                    </flux:badge>
                                @endif

                                <flux:badge size="sm" color="green">
                                    {{ __('Confirmed') }}
                                </flux:badge>

                                <flux:button
                                    variant="danger"
                                    size="sm"
                                    wire:click="cancelShift({{ $shift->id }})"
                                    data-test="cancel-shift-{{ $shift->id }}"
                                >
                                    {{ __('Cancel shift') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>

    <flux:modal wire:model="showOverCapacityModal" class="w-full max-w-md" data-test="over-capacity-warning-modal">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Please confirm') }}</flux:heading>

            @foreach ($overCapacityWarnings as $warningIndex => $warning)
                <flux:callout wire:key="over-capacity-warning-{{ $warningIndex }}" icon="exclamation-triangle" color="amber">
                    <flux:callout.text>{{ $warning }}</flux:callout.text>
                </flux:callout>
            @endforeach

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="dismissOverCapacityWarning">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="primary"
                    wire:click="acceptOverCapacityWarning"
                    data-test="accept-over-capacity-warning"
                >
                    {{ __('Continue anyway') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
