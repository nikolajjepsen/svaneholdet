<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Membership> */
class MembershipFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'role' => MembershipRole::Volunteer,
            'status' => MembershipStatus::Active,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => MembershipRole::Admin]);
    }

    public function removed(): static
    {
        return $this->state(['status' => MembershipStatus::Removed]);
    }
}
