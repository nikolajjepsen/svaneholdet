<?php

namespace App\Scheduling;

use App\Enums\MembershipStatus;
use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class HoursOverview
{
    public function __construct(
        private readonly MemberHours $memberHours,
    ) {}

    /**
     * Build the filtered Hours overview rows for the given team and season.
     *
     * @return Collection<int, array{
     *     membership: Membership,
     *     summary: HoursSummary,
     *     shifts: Collection<int, Shift>
     * }>
     */
    public function forTeam(
        Team $team,
        Season $season,
        ?string $fromDate = null,
        ?string $toDate = null,
        string $status = 'all',
        string $search = '',
        ?CarbonImmutable $now = null,
    ): Collection {
        $referenceNow = $now ?? now()->toImmutable();
        $timezone = $team->timezone;

        $fromUtc = $this->parseLocalDate($fromDate, $timezone)?->utc();
        $toExclusiveUtc = $this->parseLocalDate($toDate, $timezone)?->addDay()->utc();

        $membershipsQuery = $team->memberships()
            ->with('user');

        if ($status === 'active') {
            $membershipsQuery->where('status', MembershipStatus::Active);
        } elseif ($status === 'removed') {
            $membershipsQuery
                ->where('status', MembershipStatus::Removed)
                ->whereHas('shifts.slot', function (Builder $query) use ($season): void {
                    $query->where('season_id', $season->id);
                });
        } else {
            $membershipsQuery->where(function (Builder $query) use ($season): void {
                $query->where('status', MembershipStatus::Active)
                    ->orWhere(function (Builder $removedQuery) use ($season): void {
                        $removedQuery
                            ->where('status', MembershipStatus::Removed)
                            ->whereHas('shifts.slot', function (Builder $slotQuery) use ($season): void {
                                $slotQuery->where('season_id', $season->id);
                            });
                    });
            });
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $escaped = addcslashes(mb_strtolower($trimmedSearch), '%_\\');
            $membershipsQuery->whereHas('user', function (Builder $query) use ($escaped): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.$escaped.'%']);
            });
        }

        $memberships = $membershipsQuery
            ->get()
            ->sortBy([
                fn (Membership $membership): string => mb_strtolower($membership->user->name),
                fn (Membership $membership): int => $membership->id,
            ])
            ->values();

        if ($memberships->isEmpty()) {
            return collect();
        }

        $shiftsByMembership = Shift::query()
            ->whereIn('membership_id', $memberships->pluck('id'))
            ->where('status', ShiftStatus::Confirmed)
            ->whereHas('slot', function (Builder $query) use ($season, $fromUtc, $toExclusiveUtc): void {
                $query->where('season_id', $season->id);

                if ($fromUtc !== null) {
                    $query->where('starts_at', '>=', $fromUtc);
                }

                if ($toExclusiveUtc !== null) {
                    $query->where('starts_at', '<', $toExclusiveUtc);
                }
            })
            ->with(['slot', 'attendanceRecordedBy'])
            ->get()
            ->sortBy([
                fn (Shift $shift): int => $shift->slot->starts_at->getTimestamp(),
                fn (Shift $shift): int => $shift->slot->ends_at->getTimestamp(),
                fn (Shift $shift): int => $shift->id,
            ])
            ->groupBy('membership_id');

        return $memberships->map(function (Membership $membership) use ($shiftsByMembership, $referenceNow): array {
            /** @var Collection<int, Shift> $memberShifts */
            $memberShifts = ($shiftsByMembership->get($membership->id) ?? collect())->values();

            return [
                'membership' => $membership,
                'summary' => $this->memberHours->summarize($memberShifts, $referenceNow),
                'shifts' => $memberShifts,
            ];
        });
    }

    private function parseLocalDate(?string $date, string $timezone): ?CarbonImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $parsed;
    }
}
