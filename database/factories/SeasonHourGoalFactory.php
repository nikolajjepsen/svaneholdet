<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Season;
use App\Models\SeasonHourGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonHourGoal>
 */
class SeasonHourGoalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'membership_id' => fn (array $attributes): int => Membership::factory()
                ->create(['team_id' => Season::query()->whereKey($attributes['season_id'])->firstOrFail()->team_id])
                ->id,
            'hours' => 24,
        ];
    }
}
