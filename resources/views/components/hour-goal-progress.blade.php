@props(['summary', 'goalHours'])

@php
    $counts = __(':confirmed confirmed, :pending pending', [
        'confirmed' => $summary->confirmedCount,
        'pending' => $summary->pendingCount,
    ]);
@endphp

<flux:card size="xs">
    <flux:text variant="strong" role="status" data-test="hour-goal-progress">
        {{ $goalHours === null ? $counts : __(':progress · :counts', [
            'progress' => $summary->goalProgressFormatted($goalHours),
            'counts' => $counts,
        ]) }}
    </flux:text>
</flux:card>
