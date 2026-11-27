<?php

namespace App\Scheduling;

use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use Carbon\CarbonImmutable;

class MemberHours
{
    /**
     * Summarize planned and worked hours for a collection of shifts, deduplicating
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

        foreach ($shifts as $shift) {
            if ($shift->status !== ShiftStatus::Confirmed) {
                continue;
            }

            $slot = $shift->slot;
            $plannedStart = $slot->starts_at->getTimestamp();
            $plannedEnd = $slot->ends_at->getTimestamp();

            if ($plannedEnd > $plannedStart) {
                $plannedIntervals[] = [$plannedStart, $plannedEnd];
            }

            if ($shift->attendance_status === AttendanceStatus::NoShow) {
                continue;
            }

            if (
                $shift->attendance_status === AttendanceStatus::Adjusted
                && $shift->actual_starts_at !== null
                && $shift->actual_ends_at !== null
            ) {
                $actualStart = $shift->actual_starts_at->getTimestamp();
                $actualEnd = $shift->actual_ends_at->getTimestamp();

                if ($actualEnd > $actualStart) {
                    $workedIntervals[] = [$actualStart, $actualEnd];
                }

                continue;
            }

            if ($slot->ends_at->lessThanOrEqualTo($referenceNow) && $plannedEnd > $plannedStart) {
                $workedIntervals[] = [$plannedStart, $plannedEnd];
            }
        }

        return new HoursSummary(
            plannedMinutes: $this->mergeAndSumMinutes($plannedIntervals),
            workedMinutes: $this->mergeAndSumMinutes($workedIntervals),
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
        if ($shift->status !== ShiftStatus::Confirmed) {
            return null;
        }

        if ($shift->attendance_status === AttendanceStatus::NoShow) {
            return 0;
        }

        if (
            $shift->attendance_status === AttendanceStatus::Adjusted
            && $shift->actual_starts_at !== null
            && $shift->actual_ends_at !== null
        ) {
            return max(0, (int) $shift->actual_starts_at->diffInMinutes($shift->actual_ends_at));
        }

        $referenceNow = $now ?? now()->toImmutable();

        if ($shift->slot->ends_at->lessThanOrEqualTo($referenceNow)) {
            return $this->plannedMinutesForShift($shift);
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
