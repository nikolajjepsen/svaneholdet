<?php

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Shift;
use App\Models\Slot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slot_id' => Slot::factory(),
            'membership_id' => Membership::factory(),
            'status' => ShiftStatus::Confirmed,
            'confirmed_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ShiftStatus::Pending,
            'confirmed_at' => null,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => ShiftStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ShiftStatus::Rejected,
            'confirmed_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => ShiftStatus::Cancelled,
            'confirmed_at' => null,
        ]);
    }
}
