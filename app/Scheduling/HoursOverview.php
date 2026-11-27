<?php

namespace App\Scheduling;

use App\Enums\HoursMembershipFilter;
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
     *     seasonSummary: HoursSummary,
     *     goalHours: ?int,
     *     shortOfGoal: bool,
     *     shifts: Collection<int, Shift>
     * }>
     */
    public function forTeam(
        Team $team,
        Season $season,
        ?string $fromDate = null,
        ?string $toDate = null,
        HoursMembershipFilter $status = HoursMembershipFilter::All,
        string $search = '',
        ?CarbonImmutable $now = null,
        bool $shortOfGoal = false,
    ): Collection {
        $referenceNow = $now ?? now()->toImmutable();
        $timezone = $team->timezone;

        $fromUtc = LocalDate::parse($fromDate, $timezone)?->utc();
        $toExclusiveUtc = LocalDate::parse($toDate, $timezone)?->addDay()->utc();

        $membershipsQuery = $team->memberships()
            ->with('user');

        if ($status === HoursMembershipFilter::Active) {
            $membershipsQuery->where('status', MembershipStatus::Active);
        } elseif ($status === HoursMembershipFilter::Removed) {
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

        $goalOverrides = $season->hourGoals()->whereIn('membership_id', $memberships->pluck('id'))->get()->keyBy('membership_id');

        $shiftsByMembership = Shift::query()
            ->whereIn('membership_id', $memberships->pluck('id'))
            ->whereIn('status', [ShiftStatus::Confirmed, ShiftStatus::Pending])
            ->whereHas('slot', fn (Builder $query) => $query->where('season_id', $season->id))
            ->with(['slot', 'attendanceRecordedBy'])
            ->get()
            ->sortBy([
                fn (Shift $shift): int => $shift->slot->starts_at->getTimestamp(),
                fn (Shift $shift): int => $shift->slot->ends_at->getTimestamp(),
                fn (Shift $shift): int => $shift->id,
            ])
            ->groupBy('membership_id');

        $settings = $team->settings;
        $hasDateFilter = $fromUtc !== null || $toExclusiveUtc !== null;

        return $memberships->map(function (Membership $membership) use ($shiftsByMembership, $goalOverrides, $settings, $referenceNow, $hasDateFilter, $fromUtc, $toExclusiveUtc): array {
            /** @var Collection<int, Shift> $seasonShifts */
            $seasonShifts = $shiftsByMembership->get($membership->id) ?? collect();
            $seasonSummary = $this->memberHours->summarize($seasonShifts, $referenceNow);
            $goalHours = HourGoals::effective($settings, $goalOverrides->get($membership->id));
            $memberShifts = $seasonShifts->filter(fn (Shift $shift): bool => $shift->status === ShiftStatus::Confirmed
                && ($fromUtc === null || $shift->slot->starts_at->greaterThanOrEqualTo($fromUtc))
                && ($toExclusiveUtc === null || $shift->slot->starts_at->lessThan($toExclusiveUtc)))
                ->values();

            return [
                'membership' => $membership,
                'summary' => $hasDateFilter ? $this->memberHours->summarize($memberShifts, $referenceNow) : $seasonSummary,
                'seasonSummary' => $seasonSummary,
                'goalHours' => $goalHours,
                'shortOfGoal' => $goalHours !== null && $seasonSummary->goalProgressMinutes < $goalHours * 60,
                'shifts' => $memberShifts,
            ];
        })->filter(fn (array $row): bool => ! $shortOfGoal || $row['shortOfGoal'])->values();
    }
}
