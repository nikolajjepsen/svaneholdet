<?php

namespace App\Scheduling;

use App\Enums\MembershipStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ShiftOverlaps
{
    /**
     * @return list<string>
     */
    public function checkForMember(User $user, Slot $targetSlot, string $errorField, ?string $memberName = null): array
    {
        $team = $targetSlot->season->team;
        $overlappingShifts = $this->findOverlappingShiftsForMember($user, $targetSlot);

        $otherTeamOverlap = $overlappingShifts->first(
            fn (Shift $existingShift): bool => $existingShift->membership->team_id !== $team->id
        );

        if ($otherTeamOverlap !== null) {
            $slotLabel = SlotWindow::fromTimestamps($otherTeamOverlap->slot->starts_at, $otherTeamOverlap->slot->ends_at)
                ->formatLabel($team->timezone);
            $otherTeamName = $otherTeamOverlap->membership->team->name;

            $message = $memberName !== null
                ? __(':name already has an overlapping shift on :team (:date, :time).', [
                    'name' => $memberName,
                    'team' => $otherTeamName,
                    'date' => $slotLabel['date'],
                    'time' => $slotLabel['time'],
                ])
                : __('This slot overlaps with a shift on :team (:date, :time).', [
                    'team' => $otherTeamName,
                    'date' => $slotLabel['date'],
                    'time' => $slotLabel['time'],
                ]);

            throw ValidationException::withMessages([$errorField => $message]);
        }

        $warnings = [];

        foreach ($overlappingShifts as $sameTeamOverlap) {
            $slotLabel = SlotWindow::fromTimestamps($sameTeamOverlap->slot->starts_at, $sameTeamOverlap->slot->ends_at)
                ->formatLabel($team->timezone);

            $warnings[] = $memberName !== null
                ? __("This slot overlaps with :name's shift on :date (:time).", [
                    'name' => $memberName,
                    'date' => $slotLabel['date'],
                    'time' => $slotLabel['time'],
                ])
                : __('This slot overlaps with your shift on :date (:time).', [
                    'date' => $slotLabel['date'],
                    'time' => $slotLabel['time'],
                ]);
        }

        return $warnings;
    }

    /**
     * @param  array<int, array{slot: Slot, startsAt: CarbonImmutable, endsAt: CarbonImmutable, confirmedShifts: list<Shift>}>  $proposedSlots
     * @return list<string>
     */
    public function checkForSlotChanges(Team $team, array $proposedSlots, string $errorField): array
    {
        if ($proposedSlots === []) {
            return [];
        }

        $userIds = [];

        foreach ($proposedSlots as $entry) {
            foreach ($entry['confirmedShifts'] as $shift) {
                $userIds[$shift->membership->user_id] = true;
            }
        }

        if ($userIds === []) {
            return [];
        }

        $minStart = null;
        $maxEnd = null;

        foreach ($proposedSlots as $entry) {
            if ($minStart === null || $entry['startsAt']->lessThan($minStart)) {
                $minStart = $entry['startsAt'];
            }

            if ($maxEnd === null || $entry['endsAt']->greaterThan($maxEnd)) {
                $maxEnd = $entry['endsAt'];
            }
        }

        $editedSlotIds = array_keys($proposedSlots);

        $otherShiftsByUser = Shift::query()
            ->whereNotIn('slot_id', $editedSlotIds)
            ->whereIn('status', [ShiftStatus::Pending->value, ShiftStatus::Confirmed->value])
            ->whereHas('membership', function (Builder $query) use ($userIds): void {
                $query->whereIn('user_id', array_keys($userIds))
                    ->where('status', MembershipStatus::Active->value);
            })
            ->whereHas('slot', function (Builder $query) use ($minStart, $maxEnd): void {
                $query->where('starts_at', '<', $maxEnd)
                    ->where('ends_at', '>', $minStart);
            })
            ->with(['membership.team', 'slot'])
            ->get()
            ->sortBy(fn (Shift $shift): string => $shift->slot->starts_at->toIso8601String().'|'.$shift->slot->ends_at->toIso8601String().'|'.$shift->id)
            ->groupBy(fn (Shift $shift): int => $shift->membership->user_id);

        $crossTeamErrors = [];
        $sameTeamWarnings = [];

        foreach ($proposedSlots as $slotId => $entry) {
            $newStartsAt = $entry['startsAt'];
            $newEndsAt = $entry['endsAt'];

            foreach ($entry['confirmedShifts'] as $confirmedShift) {
                $memberUser = $confirmedShift->membership->user;

                foreach ($otherShiftsByUser->get($memberUser->id, collect()) as $otherShift) {
                    if (
                        $otherShift->slot->starts_at->greaterThanOrEqualTo($newEndsAt)
                        || $otherShift->slot->ends_at->lessThanOrEqualTo($newStartsAt)
                    ) {
                        continue;
                    }

                    $slotLabel = SlotWindow::fromTimestamps($otherShift->slot->starts_at, $otherShift->slot->ends_at)
                        ->formatLabel($team->timezone);

                    if ($otherShift->membership->team_id !== $team->id) {
                        $otherTeamName = $otherShift->membership->team->name;
                        $key = $memberUser->id.'|'.$otherShift->membership->team_id.'|'.$otherShift->slot_id;
                        $crossTeamErrors[$key] = __(':name already has an overlapping shift on :team (:date, :time).', [
                            'name' => $memberUser->name,
                            'team' => $otherTeamName,
                            'date' => $slotLabel['date'],
                            'time' => $slotLabel['time'],
                        ]);
                    } else {
                        $key = $slotId.'|'.$memberUser->id.'|'.$otherShift->slot_id;
                        $sameTeamWarnings[$key] = __("This slot overlaps with :name's shift on :date (:time).", [
                            'name' => $memberUser->name,
                            'date' => $slotLabel['date'],
                            'time' => $slotLabel['time'],
                        ]);
                    }
                }

                foreach ($proposedSlots as $otherSlotId => $otherEntry) {
                    if ($otherSlotId <= $slotId) {
                        continue;
                    }

                    $memberOnOtherSlot = false;

                    foreach ($otherEntry['confirmedShifts'] as $otherConfirmedShift) {
                        if ($otherConfirmedShift->membership->user_id === $memberUser->id) {
                            $memberOnOtherSlot = true;

                            break;
                        }
                    }

                    if (! $memberOnOtherSlot) {
                        continue;
                    }

                    if (
                        $otherEntry['startsAt']->greaterThanOrEqualTo($newEndsAt)
                        || $otherEntry['endsAt']->lessThanOrEqualTo($newStartsAt)
                    ) {
                        continue;
                    }

                    $slotLabel = SlotWindow::fromTimestamps($otherEntry['startsAt'], $otherEntry['endsAt'])
                        ->formatLabel($team->timezone);
                    $key = $slotId.'|'.$memberUser->id.'|'.$otherSlotId;
                    $sameTeamWarnings[$key] = __("This slot overlaps with :name's shift on :date (:time).", [
                        'name' => $memberUser->name,
                        'date' => $slotLabel['date'],
                        'time' => $slotLabel['time'],
                    ]);
                }
            }
        }

        if ($crossTeamErrors !== []) {
            throw ValidationException::withMessages([
                $errorField => array_values($crossTeamErrors),
            ]);
        }

        return array_values($sameTeamWarnings);
    }

    /**
     * @return Collection<int, Shift>
     */
    private function findOverlappingShiftsForMember(User $user, Slot $targetSlot): Collection
    {
        return Shift::query()
            ->where('slot_id', '!=', $targetSlot->id)
            ->whereIn('status', [ShiftStatus::Pending->value, ShiftStatus::Confirmed->value])
            ->whereHas('membership', function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->where('status', MembershipStatus::Active->value);
            })
            ->whereHas('slot', function (Builder $query) use ($targetSlot): void {
                $query->where('starts_at', '<', $targetSlot->ends_at)
                    ->where('ends_at', '>', $targetSlot->starts_at);
            })
            ->with(['membership.team', 'slot'])
            ->get()
            ->sortBy(fn (Shift $shift): string => $shift->slot->starts_at->toIso8601String().'|'.$shift->slot->ends_at->toIso8601String().'|'.$shift->id)
            ->values();
    }
}
