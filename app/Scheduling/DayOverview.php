<?php

namespace App\Scheduling;

use App\Models\Season;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class DayOverview
{
    public function defaultDate(Season $season, string $timezone, ?CarbonImmutable $now = null): string
    {
        $todayLocal = ($now ?? now()->toImmutable())->setTimezone($timezone);
        $todayDate = $todayLocal->format('Y-m-d');

        [$todayStartUtc, $todayEndUtc] = $this->utcBoundsForDate($todayDate, $timezone);

        $hasSlotsToday = $season->slots()
            ->where('starts_at', '<', $todayEndUtc)
            ->where('ends_at', '>', $todayStartUtc)
            ->exists();

        if ($hasSlotsToday) {
            return $todayDate;
        }

        $nextSlot = $season->slots()
            ->where('starts_at', '>=', $todayEndUtc)
            ->orderBy('starts_at')
            ->first();

        if ($nextSlot !== null) {
            return $nextSlot->starts_at->setTimezone($timezone)->format('Y-m-d');
        }

        return $todayDate;
    }

    /**
     * @return array{
     *     slots: Collection<int, Slot>,
     *     uniqueConfirmedMembersCount: int
     * }
     */
    public function forDate(Season $season, string $date, string $timezone): array
    {
        [$dayStartUtc, $dayEndUtc] = $this->utcBoundsForDate($date, $timezone);

        $slots = $season->slots()
            ->where('starts_at', '<', $dayEndUtc)
            ->where('ends_at', '>', $dayStartUtc)
            ->withCount('pendingShifts')
            ->with([
                'confirmedShifts' => fn ($query) => $query
                    ->select(['id', 'slot_id', 'membership_id'])
                    ->orderBy('confirmed_at')
                    ->orderBy('id')
                    ->with('membership:id,role,user_id', 'membership.user:id,name'),
            ])
            ->orderBy('starts_at')
            ->orderBy('ends_at')
            ->orderBy('id')
            ->get();

        $uniqueConfirmedMembersCount = $slots
            ->flatMap(fn (Slot $slot) => $slot->confirmedShifts->pluck('membership_id'))
            ->unique()
            ->count();

        return [
            'slots' => $slots,
            'uniqueConfirmedMembersCount' => $uniqueConfirmedMembersCount,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcBoundsForDate(string $date, string $timezone): array
    {
        $dayStartLocal = LocalDate::parse($date, $timezone)
            ?? now()->toImmutable()->setTimezone($timezone)->startOfDay();
        $dayEndLocal = $dayStartLocal->addDay();

        return [$dayStartLocal->utc(), $dayEndLocal->utc()];
    }
}
