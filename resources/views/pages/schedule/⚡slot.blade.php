<?php

use App\Auth\CurrentTeam;
use App\Enums\AttendanceStatus;
use App\Models\Membership;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\Shifts;
use App\Scheduling\Slots;
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

    #[Locked]
    public int $slotId;

    #[Locked]
    public ?string $statusMessage = null;

    public ?int $assignMembershipId = null;

    public string $startTime = '08:00';

    public string $endTime = '16:00';

    public int $capacity = 1;

    public bool $showOverCapacityModal = false;

    #[Locked]
    public ?string $overCapacityAction = null;

    #[Locked]
    public ?int $overCapacityValue = null;

    #[Locked]
    public ?string $pendingStartTime = null;

    #[Locked]
    public ?string $pendingEndTime = null;

    /** @var list<string> */
    #[Locked]
    public array $overCapacityWarnings = [];

    public bool $showDeleteModal = false;

    public bool $notifyMembersOnDelete = true;

    /** @var array<int, array{startTime: string, endTime: string}> */
    public array $attendanceTimes = [];

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
        $this->startTime = $slot->starts_at->setTimezone($team->timezone)->format('H:i');
        $this->endTime = $slot->ends_at->setTimezone($team->timezone)->format('H:i');
        $this->capacity = $slot->capacity;

        $this->syncAttendanceTimes(overwriteExisting: true);
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

    public function updateTimes(Slots $slots): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        Gate::authorize('update', $this->slot);

        $validated = $this->validate([
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'different:startTime'],
        ]);

        $this->performUpdateTimes($validated['startTime'], $validated['endTime'], $slots, acceptWarnings: false);
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

    public function openDeleteModal(): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();
        $this->notifyMembersOnDelete = true;
        $this->showDeleteModal = true;
    }

    public function deleteSlot(Slots $slots): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        Gate::authorize('delete', $this->slot);

        $slots->delete($this->slot, $this->user(), notifyMembers: $this->notifyMembersOnDelete);

        $this->showDeleteModal = false;
        session()->flash('status', __('Slot deleted.'));

        $this->redirectRoute('schedule.index', navigate: true);
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
        unset($this->attendanceTimes[$shiftId]);

        $this->statusMessage = __('Shift cancelled.');
    }

    public function saveAttendance(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        $shift = Shift::query()->with('membership')->findOrFail($shiftId);
        abort_unless($shift->slot_id === $this->slotId, 403);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('recordAttendance', $shift);

        $startKey = "attendanceTimes.{$shiftId}.startTime";
        $endKey = "attendanceTimes.{$shiftId}.endTime";

        $this->validate([
            $startKey => ['required', 'date_format:H:i'],
            $endKey => ['required', 'date_format:H:i'],
        ]);

        $startTime = (string) ($this->attendanceTimes[$shiftId]['startTime'] ?? '');
        $endTime = (string) ($this->attendanceTimes[$shiftId]['endTime'] ?? '');

        $updated = $shifts->adjustAttendance(
            shift: $shift,
            actor: $this->user(),
            startTime: $startTime,
            endTime: $endTime,
            startErrorKey: $startKey,
            endErrorKey: $endKey,
        );

        $this->setShiftAttendanceInputs($updated);
        $this->statusMessage = __('Attendance updated.');
    }

    public function markNoShow(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        $shift = Shift::query()->with('membership')->findOrFail($shiftId);
        abort_unless($shift->slot_id === $this->slotId, 403);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('recordAttendance', $shift);

        $updated = $shifts->markNoShow($shift, $this->user());

        $this->setShiftAttendanceInputs($updated);
        $this->statusMessage = __('Marked as no-show.');
    }

    public function resetAttendance(int $shiftId, Shifts $shifts): void
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        $shift = Shift::query()->with('membership')->findOrFail($shiftId);
        abort_unless($shift->slot_id === $this->slotId, 403);
        abort_unless($shift->membership->team_id === $this->teamId, 403);
        Gate::authorize('recordAttendance', $shift);

        $updated = $shifts->resetAttendance($shift, $this->user());

        $this->setShiftAttendanceInputs($updated);
        $this->statusMessage = __('Attendance reset to planned.');
    }

    public function acceptOverCapacityWarning(Shifts $shifts, Slots $slots): void
    {
        $this->statusMessage = null;

        $action = $this->overCapacityAction;
        $value = $this->overCapacityValue;
        $pendingStart = $this->pendingStartTime;
        $pendingEnd = $this->pendingEndTime;

        $this->dismissOverCapacityWarning();

        if ($action === null) {
            return;
        }

        if ($action === 'times') {
            if ($pendingStart !== null && $pendingEnd !== null) {
                $this->performUpdateTimes($pendingStart, $pendingEnd, $slots, acceptWarnings: true);
            }

            return;
        }

        if ($value === null) {
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
        $this->pendingStartTime = null;
        $this->pendingEndTime = null;
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

    private function performUpdateTimes(string $startTime, string $endTime, Slots $slots, bool $acceptWarnings): void
    {
        Gate::authorize('update', $this->slot);

        $result = $slots->updateTimes($this->slot, $this->user(), $startTime, $endTime, acceptWarnings: $acceptWarnings);

        if ($result->hasWarnings()) {
            $this->pendingStartTime = $startTime;
            $this->pendingEndTime = $endTime;
            $this->openOverCapacityModal('times', null, $result->warnings);

            return;
        }

        if ($result->slot !== null) {
            $this->slot = $result->slot;
            $this->startTime = $result->slot->starts_at->setTimezone($this->team->timezone)->format('H:i');
            $this->endTime = $result->slot->ends_at->setTimezone($this->team->timezone)->format('H:i');
            $this->syncAttendanceTimes(overwriteExisting: true);
        }

        $this->statusMessage = __('Slot times updated.');
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
    private function openOverCapacityModal(string $action, ?int $value, array $warnings): void
    {
        $this->overCapacityAction = $action;
        $this->overCapacityValue = $value;
        $this->overCapacityWarnings = $warnings;
        $this->showOverCapacityModal = true;
    }

    /**
     * @param  Collection<int, Shift>|null  $confirmedShifts
     */
    private function syncAttendanceTimes(bool $overwriteExisting = false, ?Collection $confirmedShifts = null): void
    {
        $shifts = $confirmedShifts ?? $this->slot->confirmedShifts()->get();

        foreach ($shifts as $shift) {
            if (! $overwriteExisting && array_key_exists($shift->id, $this->attendanceTimes)) {
                continue;
            }

            $this->setShiftAttendanceInputs($shift);
        }
    }

    private function setShiftAttendanceInputs(Shift $shift): void
    {
        $timezone = $this->team->timezone;
        $startsAt = $shift->attendance_status === AttendanceStatus::Adjusted && $shift->actual_starts_at !== null
            ? $shift->actual_starts_at
            : $this->slot->starts_at;
        $endsAt = $shift->attendance_status === AttendanceStatus::Adjusted && $shift->actual_ends_at !== null
            ? $shift->actual_ends_at
            : $this->slot->ends_at;

        $this->attendanceTimes[$shift->id] = [
            'startTime' => $startsAt->setTimezone($timezone)->format('H:i'),
            'endTime' => $endsAt->setTimezone($timezone)->format('H:i'),
        ];
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
            ->with(['membership.user', 'attendanceRecordedBy'])
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get()
            ->values();

        $this->syncAttendanceTimes(overwriteExisting: false, confirmedShifts: $confirmedShifts);

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
            'hasStarted' => $this->slot->hasStarted(),
            'confirmedShifts' => $confirmedShifts,
            'pendingShifts' => $pendingShifts,
            'assignableMemberships' => $assignableMemberships,
        ])->title(__('Slot details'));
    }
}; ?>

<section class="max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('schedule.index')" wire:navigate>
            {{ __('Back to schedule') }}
        </flux:button>

        <div class="flex flex-wrap items-center gap-2">
            @if ($currentSlot->slot_series_id !== null)
                <flux:button
                    variant="ghost"
                    size="sm"
                    :href="route('schedule.series.show', $currentSlot->slot_series_id)"
                    wire:navigate
                    data-test="open-slot-series"
                >
                    {{ __('Edit series') }}
                </flux:button>
            @endif

            @if (! $hasStarted)
                <flux:button
                    variant="danger"
                    size="sm"
                    wire:click="openDeleteModal"
                    data-test="open-delete-slot"
                >
                    {{ __('Delete slot') }}
                </flux:button>
            @endif
        </div>
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
            $pendingCount = $pendingShifts->count();
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

    @if ($errors->has('shift') || $errors->has('slot') || $errors->has('attendance'))
        <flux:callout icon="exclamation-circle" color="red" role="alert">
            <flux:callout.text>{{ $errors->first('shift') ?: $errors->first('slot') ?: $errors->first('attendance') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($errors->has('times'))
        <flux:callout icon="exclamation-circle" color="red" role="alert" data-test="slot-times-errors">
            <div class="space-y-1">
                @foreach ($errors->get('times') as $timeError)
                    <flux:callout.text>{{ $timeError }}</flux:callout.text>
                @endforeach
            </div>
        </flux:callout>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading size="md" level="2">{{ __('Slot times') }}</flux:heading>

            <form wire:submit="updateTimes" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Start time') }}</flux:label>
                        <flux:input type="time" name="startTime" wire:model="startTime" data-test="slot-start-time-input" required />
                        <flux:error name="startTime" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('End time') }}</flux:label>
                        <flux:input type="time" name="endTime" wire:model="endTime" data-test="slot-end-time-input" required />
                        <flux:error name="endTime" />
                    </flux:field>
                </div>

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" size="sm" data-test="save-times-submit">
                        {{ __('Save times') }}
                    </flux:button>
                </div>
            </form>
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
                    @php
                        $actualStartsLocal = $shift->actual_starts_at?->setTimezone($team->timezone);
                        $actualEndsLocal = $shift->actual_ends_at?->setTimezone($team->timezone);
                        $recordedLocal = $shift->attendance_recorded_at?->setTimezone($team->timezone);
                    @endphp
                    <flux:card wire:key="confirmed-shift-{{ $shift->id }}" data-test="confirmed-shift-{{ $shift->id }}" class="space-y-4">
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

                                @if ($shift->attendance_status === AttendanceStatus::Adjusted && $actualStartsLocal !== null && $actualEndsLocal !== null)
                                    <flux:badge size="sm" color="blue" data-test="attendance-status-{{ $shift->id }}">
                                        {{ __('Adjusted') }} · {{ $actualStartsLocal->format('H:i') }}–{{ $actualEndsLocal->format('H:i') }}
                                    </flux:badge>
                                @elseif ($shift->attendance_status === AttendanceStatus::NoShow)
                                    <flux:badge size="sm" color="red" data-test="attendance-status-{{ $shift->id }}">
                                        {{ __('No-show') }}
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

                        <div class="border-t border-zinc-200 pt-3 dark:border-zinc-700 space-y-2">
                            <form
                                wire:submit="saveAttendance({{ $shift->id }})"
                                class="flex flex-wrap items-end gap-3"
                            >
                                <flux:field class="w-32">
                                    <flux:label>{{ __('Actual start') }}</flux:label>
                                    <flux:input
                                        type="time"
                                        name="attendanceStartTime{{ $shift->id }}"
                                        wire:model="attendanceTimes.{{ $shift->id }}.startTime"
                                        data-test="attendance-start-{{ $shift->id }}"
                                        required
                                    />
                                    <flux:error name="attendanceTimes.{{ $shift->id }}.startTime" />
                                </flux:field>

                                <flux:field class="w-32">
                                    <flux:label>{{ __('Actual end') }}</flux:label>
                                    <flux:input
                                        type="time"
                                        name="attendanceEndTime{{ $shift->id }}"
                                        wire:model="attendanceTimes.{{ $shift->id }}.endTime"
                                        data-test="attendance-end-{{ $shift->id }}"
                                        required
                                    />
                                    <flux:error name="attendanceTimes.{{ $shift->id }}.endTime" />
                                </flux:field>

                                <div class="flex flex-wrap items-center gap-2 pb-0.5">
                                    <flux:button
                                        type="submit"
                                        variant="primary"
                                        size="sm"
                                        data-test="attendance-save-{{ $shift->id }}"
                                    >
                                        {{ __('Save') }}
                                    </flux:button>

                                    <flux:button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        wire:click="markNoShow({{ $shift->id }})"
                                        data-test="attendance-no-show-{{ $shift->id }}"
                                    >
                                        {{ __('No-show') }}
                                    </flux:button>

                                    @if ($shift->hasAttendanceOverride())
                                        <flux:button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            wire:click="resetAttendance({{ $shift->id }})"
                                            data-test="attendance-reset-{{ $shift->id }}"
                                        >
                                            {{ __('Reset to planned') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </form>

                            @if ($shift->hasAttendanceOverride() && $recordedLocal !== null)
                                <flux:text class="text-xs" data-test="attendance-audit-{{ $shift->id }}">
                                    @if ($shift->attendanceRecordedBy !== null)
                                        {{ __('Edited by :name on :time', ['name' => $shift->attendanceRecordedBy->name, 'time' => $recordedLocal->translatedFormat('j. M Y H:i')]) }}
                                    @else
                                        {{ __('Edited on :time', ['time' => $recordedLocal->translatedFormat('j. M Y H:i')]) }}
                                    @endif
                                </flux:text>
                            @endif
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

    <flux:modal wire:model="showDeleteModal" class="w-full max-w-md" data-test="delete-slot-modal">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Delete slot') }}</flux:heading>

            <flux:text>
                {{ __('This slot has :confirmed confirmed and :pending pending shifts. Deleting it removes all shifts on this slot.', ['confirmed' => $confirmedCount, 'pending' => $pendingCount]) }}
            </flux:text>

            <flux:checkbox
                wire:model="notifyMembersOnDelete"
                :label="__('Email affected members that this slot is cancelled')"
                data-test="delete-slot-notify-checkbox"
            />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="danger"
                    wire:click="deleteSlot"
                    data-test="confirm-delete-slot"
                >
                    {{ __('Delete slot') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
