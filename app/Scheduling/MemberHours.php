<?php

namespace App\Scheduling;

use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use Carbon\CarbonImmutable;

class MemberHours
{
    /**
     * Summarize planned hours, worked hours, and goal progress for shifts, deduplicating
     * overlapping half-open [start, end) intervals before summing durations.
     *
     * @param  iterable<int, Shift>  $shifts
     */
    public function summarize(iterable $shifts, ?CarbonImmutable $now = null): HoursSummary
    {
        $referenceNow = $now ?? now()->toImmutable();

        /** @var list<array{0: int, 1: int}> $plannedIntervals */
        $plannedIntervals = [];

        /** @var list<array{0: int, 1: int}> $workedIntervals */
        $workedIntervals = [];

        /** @var list<array{0: int, 1: int}> $goalIntervals */
        $goalIntervals = [];

        $confirmedCount = 0;
        $pendingCount = 0;

        foreach ($shifts as $shift) {
            if (! in_array($shift->status, [ShiftStatus::Confirmed, ShiftStatus::Pending], true)) {
                continue;
            }

            $slot = $shift->slot;
            $plannedStart = $slot->starts_at->getTimestamp();
            $plannedEnd = $slot->ends_at->getTimestamp();

            $plannedInterval = [$plannedStart, $plannedEnd];

            if ($shift->status === ShiftStatus::Pending) {
                $pendingCount++;

                if ($plannedEnd > $plannedStart) {
                    $goalIntervals[] = $plannedInterval;
                }

                continue;
            }

            $confirmedCount++;

            if ($plannedEnd > $plannedStart) {
                $plannedIntervals[] = $plannedInterval;
            }

            $workedInterval = $this->workedIntervalForShift($shift, $referenceNow);

            if ($workedInterval !== null && $workedInterval[1] > $workedInterval[0]) {
                $workedIntervals[] = $workedInterval;
            }

            $goalInterval = $workedInterval ?? $plannedInterval;

            if ($goalInterval[1] > $goalInterval[0]) {
                $goalIntervals[] = $goalInterval;
            }
        }

        return new HoursSummary(
            plannedMinutes: $this->mergeAndSumMinutes($plannedIntervals),
            workedMinutes: $this->mergeAndSumMinutes($workedIntervals),
            goalProgressMinutes: $this->mergeAndSumMinutes($goalIntervals),
            confirmedCount: $confirmedCount,
            pendingCount: $pendingCount,
        );
    }

    /**
     * Planned duration in minutes for a single shift.
     */
    public function plannedMinutesForShift(Shift $shift): int
    {
        return max(0, (int) $shift->slot->starts_at->diffInMinutes($shift->slot->ends_at));
    }

    /**
     * Worked duration in minutes for a single confirmed shift, or null when not yet eligible.
     */
    public function workedMinutesForShift(Shift $shift, ?CarbonImmutable $now = null): ?int
    {
        $interval = $this->workedIntervalForShift($shift, $now ?? now()->toImmutable());

        if ($interval === null) {
            return null;
        }

        return intdiv($interval[1] - $interval[0], 60);
    }

    /**
     * Resolve the worked [start, end) interval in Unix seconds for a confirmed shift,
     * returning [0, 0] for a no-show and null when the shift is not yet eligible.
     *
     * @return array{0: int, 1: int}|null
     */
    private function workedIntervalForShift(Shift $shift, CarbonImmutable $now): ?array
    {
        if ($shift->status !== ShiftStatus::Confirmed) {
            return null;
        }

        if ($shift->attendance_status === AttendanceStatus::NoShow) {
            return [0, 0];
        }

        if (
            $shift->attendance_status === AttendanceStatus::Adjusted
            && $shift->actual_starts_at !== null
            && $shift->actual_ends_at !== null
        ) {
            $actualStart = $shift->actual_starts_at->getTimestamp();
            $actualEnd = $shift->actual_ends_at->getTimestamp();

            return $actualEnd > $actualStart ? [$actualStart, $actualEnd] : [0, 0];
        }

        $slot = $shift->slot;

        if ($slot->ends_at->lessThanOrEqualTo($now)) {
            $plannedStart = $slot->starts_at->getTimestamp();
            $plannedEnd = $slot->ends_at->getTimestamp();

            return $plannedEnd > $plannedStart ? [$plannedStart, $plannedEnd] : [0, 0];
        }

        return null;
    }

    /**
     * Format a duration in minutes using localized hour/minute labels.
     */
    public static function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return __(':hours h', ['hours' => 0]);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours > 0 && $remainingMinutes > 0) {
            return __(':hours h :minutes m', [
                'hours' => $hours,
                'minutes' => $remainingMinutes,
            ]);
        }

        if ($hours > 0) {
            return __(':hours h', ['hours' => $hours]);
        }

        return __(':minutes m', ['minutes' => $remainingMinutes]);
    }

    /**
     * Merge overlapping [start, end) intervals in Unix seconds and return the total duration in minutes.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function mergeAndSumMinutes(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }

        usort($intervals, static function (array $a, array $b): int {
            if ($a[0] !== $b[0]) {
                return $a[0] <=> $b[0];
            }

            return $a[1] <=> $b[1];
        });

        $totalSeconds = 0;
        [$currentStart, $currentEnd] = $intervals[0];

        $count = count($intervals);
        for ($i = 1; $i < $count; $i++) {
            [$nextStart, $nextEnd] = $intervals[$i];

            if ($nextStart <= $currentEnd) {
                if ($nextEnd > $currentEnd) {
                    $currentEnd = $nextEnd;
                }
            } else {
                $totalSeconds += $currentEnd - $currentStart;
                $currentStart = $nextStart;
                $currentEnd = $nextEnd;
            }
        }

        $totalSeconds += $currentEnd - $currentStart;

        return intdiv($totalSeconds, 60);
    }
}
