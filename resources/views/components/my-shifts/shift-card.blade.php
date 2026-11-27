@props([
    'shift',
    'team',
    'timezone',
    'memberHours',
    'now',
    'section' => 'upcoming',
])

@php
    use App\Enums\AttendanceStatus;
    use App\Enums\ShiftStatus;
    use App\Scheduling\MemberHours;

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

<flux:card wire:key="{{ $section }}-shift-{{ $shift->id }}" data-test="{{ $section }}-shift-{{ $shift->id }}">
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
                    @if (! $actualStartsLocal->isSameDay($actualEndsLocal->subSecond()))
                        ({{ __('Ends next day') }})
                    @endif
                </flux:text>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge size="sm" :color="$badgeColor">
                {{ $shift->status->label() }}
            </flux:badge>

            @if ($isAdjusted || $isNoShow)
                <flux:badge size="sm" :color="$shift->attendance_status->badgeColor()" data-test="shift-attendance-{{ $shift->id }}">
                    {{ $shift->attendance_status->label() }}
                </flux:badge>
            @endif

            @if ($section === 'upcoming' && $shift->status === ShiftStatus::Confirmed)
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
