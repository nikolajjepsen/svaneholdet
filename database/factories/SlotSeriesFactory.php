<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\SlotSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlotSeries>
 */
class SlotSeriesFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'local_start_time' => '08:00',
            'local_end_time' => '16:00',
            'capacity' => 4,
        ];
    }
}
