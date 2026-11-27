<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
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
            'attendance_status' => AttendanceStatus::Planned,
            'actual_starts_at' => null,
            'actual_ends_at' => null,
            'attendance_recorded_by' => null,
            'attendance_recorded_at' => null,
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

    public function adjusted(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?User $recordedBy = null,
        ?CarbonImmutable $recordedAt = null,
    ): static {
        return $this->state(fn (): array => [
            'status' => ShiftStatus::Confirmed,
            'confirmed_at' => now(),
            'attendance_status' => AttendanceStatus::Adjusted,
            'actual_starts_at' => $startsAt->utc(),
            'actual_ends_at' => $endsAt->utc(),
            'attendance_recorded_by' => $recordedBy?->id,
            'attendance_recorded_at' => ($recordedAt ?? now())->utc(),
        ]);
    }

    public function noShow(
        ?User $recordedBy = null,
        ?CarbonImmutable $recordedAt = null,
    ): static {
        return $this->state(fn (): array => [
            'status' => ShiftStatus::Confirmed,
            'confirmed_at' => now(),
            'attendance_status' => AttendanceStatus::NoShow,
            'actual_starts_at' => null,
            'actual_ends_at' => null,
            'attendance_recorded_by' => $recordedBy?->id,
            'attendance_recorded_at' => ($recordedAt ?? now())->utc(),
        ]);
    }
}
