<?php

namespace App\Scheduling;

use App\Models\Season;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type RolloutInterval array{start: string, end: string, capacity: int}
 */
class Rollout
{
    /**
     * @param  array<int, string>  $dates
     * @param  array<int, RolloutInterval>  $intervals
     */
    public function preview(Season $season, array $dates, array $intervals): RolloutPreview
    {
        $normalizedDates = $this->normalizeDates($dates);
        $normalizedIntervals = $this->normalizeIntervals($intervals);

        $season->loadMissing('team');
        $timezone = $season->team->timezone;

        $planned = [];
        $startTimestamps = [];

        foreach ($normalizedDates as $date) {
            foreach ($normalizedIntervals as $intervalIndex => $interval) {
                $window = SlotWindow::fromLocal(
                    $date,
                    $interval['start'],
                    $interval['end'],
                    $timezone,
                    startErrorKey: 'intervals',
                    endErrorKey: 'intervals',
                );

                $startTimestamps[$window->startsAt->toIso8601String()] = $window->startsAt;

                $planned[] = [
                    'date' => $date,
                    'startsAt' => $window->startsAt,
                    'endsAt' => $window->endsAt,
                    'startTime' => $interval['start'],
                    'endTime' => $interval['end'],
                    'endsNextDay' => $window->endsNextDay,
                    'capacity' => $interval['capacity'],
                    'isDuplicate' => false,
                    'intervalIndex' => $intervalIndex,
                ];
            }
        }

        $existingKeys = [];

        if ($startTimestamps !== []) {
            $existingSlots = $season->slots()
                ->whereIn('starts_at', array_values($startTimestamps))
                ->get(['starts_at', 'ends_at']);

            foreach ($existingSlots as $existingSlot) {
                $existingKeys[$this->timestampKey($existingSlot->starts_at, $existingSlot->ends_at)] = true;
            }
        }

        $plannedSlots = [];
        $duplicates = [];

        foreach ($planned as $item) {
            $isDuplicate = isset($existingKeys[$this->timestampKey($item['startsAt'], $item['endsAt'])]);
            $item['isDuplicate'] = $isDuplicate;
            $plannedSlots[] = $item;

            if ($isDuplicate) {
                $duplicates[] = $item;
            }
        }

        return new RolloutPreview(
            slotCount: count($plannedSlots),
            dayCount: count($normalizedDates),
            duplicateCount: count($duplicates),
            plannedSlots: $plannedSlots,
            duplicates: $duplicates,
        );
    }

    /**
     * @param  array<int, string>  $dates
     * @param  array<int, RolloutInterval>  $intervals
     * @return Collection<int, SlotSeries>
     */
    public function save(Season $season, User $actor, array $dates, array $intervals): Collection
    {
        Gate::forUser($actor)->authorize('create', [SlotSeries::class, $season]);
        Gate::forUser($actor)->authorize('create', [Slot::class, $season]);

        $normalizedDates = $this->normalizeDates($dates);
        $normalizedIntervals = $this->normalizeIntervals($intervals);

        $season->loadMissing('team');
        $timezone = $season->team->timezone;

        return DB::transaction(function () use ($season, $normalizedDates, $normalizedIntervals, $timezone): Collection {
            $createdSeries = new Collection;

            foreach ($normalizedIntervals as $interval) {
                $series = SlotSeries::query()->create([
                    'season_id' => $season->id,
                    'local_start_time' => $interval['start'],
                    'local_end_time' => $interval['end'],
                    'capacity' => $interval['capacity'],
                ]);

                foreach ($normalizedDates as $date) {
                    $window = SlotWindow::fromLocal(
                        $date,
                        $interval['start'],
                        $interval['end'],
                        $timezone,
                        startErrorKey: 'intervals',
                        endErrorKey: 'intervals',
                    );

                    Slot::query()->create([
                        'season_id' => $season->id,
                        'slot_series_id' => $series->id,
                        'starts_at' => $window->startsAt,
                        'ends_at' => $window->endsAt,
                        'capacity' => $interval['capacity'],
                    ]);
                }

                $createdSeries->push($series);
            }

            return $createdSeries;
        });
    }

    /**
     * @param  array<int, string>  $dates
     * @return list<string>
     */
    private function normalizeDates(array $dates): array
    {
        $unique = array_values(array_unique(array_map('trim', $dates)));
        sort($unique);

        if ($unique === []) {
            throw ValidationException::withMessages([
                'selectedDates' => __('Pick at least one date.'),
            ]);
        }

        foreach ($unique as $date) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

            if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
                throw ValidationException::withMessages([
                    'selectedDates' => __('Enter valid dates.'),
                ]);
            }
        }

        return $unique;
    }

    /**
     * @param  array<int, RolloutInterval>  $intervals
     * @return list<RolloutInterval>
     */
    private function normalizeIntervals(array $intervals): array
    {
        $values = array_values($intervals);

        if ($values === []) {
            throw ValidationException::withMessages([
                'intervals' => __('Add at least one interval.'),
            ]);
        }

        $normalized = [];

        foreach ($values as $index => $interval) {
            $start = trim($interval['start']);
            $end = trim($interval['end']);
            $capacity = $interval['capacity'];

            $parsedStart = CarbonImmutable::createFromFormat('!H:i', $start, 'UTC');
            if ($parsedStart === null || $parsedStart->format('H:i') !== $start) {
                throw ValidationException::withMessages([
                    "intervals.{$index}.start" => __('Enter a valid start time.'),
                ]);
            }

            $parsedEnd = CarbonImmutable::createFromFormat('!H:i', $end, 'UTC');
            if ($parsedEnd === null || $parsedEnd->format('H:i') !== $end) {
                throw ValidationException::withMessages([
                    "intervals.{$index}.end" => __('Enter a valid end time.'),
                ]);
            }

            if ($end === $start) {
                throw ValidationException::withMessages([
                    "intervals.{$index}.end" => __('End time must be different from start time.'),
                ]);
            }

            if ($capacity < 1) {
                throw ValidationException::withMessages([
                    "intervals.{$index}.capacity" => __('Capacity must be at least 1.'),
                ]);
            }

            $normalized[] = [
                'start' => $start,
                'end' => $end,
                'capacity' => $capacity,
            ];
        }

        return $normalized;
    }

    private function timestampKey(CarbonImmutable $startsAt, CarbonImmutable $endsAt): string
    {
        return $startsAt->utc()->format('Y-m-d H:i:s').'|'.$endsAt->utc()->format('Y-m-d H:i:s');
    }
}
