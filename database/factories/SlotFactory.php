<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Slot>
 */
class SlotFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = CarbonImmutable::parse('2027-08-04 08:00:00', 'Europe/Copenhagen')->utc();

        return [
            'season_id' => Season::factory(),
            'slot_series_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(8),
            'capacity' => 4,
        ];
    }
}
