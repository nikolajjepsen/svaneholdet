<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * @phpstan-type PlannedSlot array{
 *     date: string,
 *     startsAt: CarbonImmutable,
 *     endsAt: CarbonImmutable,
 *     startTime: string,
 *     endTime: string,
 *     endsNextDay: bool,
 *     capacity: int,
 *     isAdminOnly: bool,
 *     isDuplicate: bool,
 *     intervalIndex: int,
 * }
 */
final readonly class RolloutPreview
{
    /**
     * @param  list<PlannedSlot>  $plannedSlots
     * @param  list<PlannedSlot>  $duplicates
     */
    public function __construct(
        public int $slotCount,
        public int $dayCount,
        public int $duplicateCount,
        public array $plannedSlots,
        public array $duplicates,
    ) {}

    public function hasDuplicates(): bool
    {
        return $this->duplicateCount > 0;
    }

    public function summary(): string
    {
        $slotsPart = $this->slotCount === 1
            ? __('1 slot')
            : __(':count slots', ['count' => $this->slotCount]);

        $daysPart = $this->dayCount === 1
            ? __('1 day')
            : __(':count days', ['count' => $this->dayCount]);

        $base = __(':slots on :days', [
            'slots' => $slotsPart,
            'days' => $daysPart,
        ]);

        if ($this->duplicateCount === 0) {
            return $base;
        }

        $duplicatesPart = $this->duplicateCount === 1
            ? __('1 already exists')
            : __(':count already exist', ['count' => $this->duplicateCount]);

        return __(':summary, :duplicates', [
            'summary' => $base,
            'duplicates' => $duplicatesPart,
        ]);
    }
}
