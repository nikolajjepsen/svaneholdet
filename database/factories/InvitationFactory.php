<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invitation> */
class InvitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'invited_by' => User::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'token_hash' => hash('sha256', random_bytes(32)),
            'expires_at' => now()->addDays(14),
        ];
    }
}
