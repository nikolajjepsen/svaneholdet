<?php

namespace App\Scheduling;

readonly class HoursSummary
{
    public function __construct(
        public int $plannedMinutes,
        public int $workedMinutes,
        public int $goalProgressMinutes,
        public int $confirmedCount,
        public int $pendingCount,
    ) {}

    public function plannedHours(): float
    {
        return round($this->plannedMinutes / 60, 2);
    }

    public function workedHours(): float
    {
        return round($this->workedMinutes / 60, 2);
    }

    public function plannedFormatted(): string
    {
        return MemberHours::formatDuration($this->plannedMinutes);
    }

    public function workedFormatted(): string
    {
        return MemberHours::formatDuration($this->workedMinutes);
    }

    public function goalProgressFormatted(int $goalHours): string
    {
        $progress = $this->goalProgressMinutes % 60 === 0
            ? intdiv($this->goalProgressMinutes, 60)
            : MemberHours::formatDuration($this->goalProgressMinutes);

        return __(':progress / :goal h', ['progress' => $progress, 'goal' => $goalHours]);
    }
}
