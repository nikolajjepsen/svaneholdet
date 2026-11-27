<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects a slot whose end is not after its start', function () {
    $team = Team::factory()->create();
    $season = Season::factory()->for($team)->create();
    $time = CarbonImmutable::parse('2027-08-04 08:00:00', 'UTC');

    expect(fn () => DB::table('slots')->insert([
        'season_id' => $season->id,
        'starts_at' => $time,
        'ends_at' => $time,
        'capacity' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('slots')->insert([
        'season_id' => $season->id,
        'starts_at' => $time,
        'ends_at' => $time->subHour(),
        'capacity' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a slot with capacity less than one', function () {
    $team = Team::factory()->create();
    $season = Season::factory()->for($team)->create();
    $start = CarbonImmutable::parse('2027-08-04 08:00:00', 'UTC');

    expect(fn () => DB::table('slots')->insert([
        'season_id' => $season->id,
        'starts_at' => $start,
        'ends_at' => $start->addHours(4),
        'capacity' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces a partial unique index on active shifts per slot and membership', function () {
    $team = Team::factory()->create();
    $season = Season::factory()->for($team)->create();
    $slot = Slot::factory()->for($season)->create();
    $membership = Membership::factory()->for($team)->create();

    Shift::factory()->for($slot)->for($membership)->cancelled()->create();
    Shift::factory()->for($slot)->for($membership)->rejected()->create();

    Shift::factory()->for($slot)->for($membership)->confirmed()->create();

    expect(fn () => DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $membership->id,
        'status' => ShiftStatus::Pending->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $membership->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces attendance constraints on shifts', function () {
    $team = Team::factory()->create();
    $season = Season::factory()->for($team)->create();
    $slot = Slot::factory()->for($season)->create();
    $start = CarbonImmutable::parse('2027-08-04 12:00:00', 'UTC');

    // Valid planned, adjusted, and no-show rows are accepted
    $plannedMembership = Membership::factory()->for($team)->create();
    $adjustedMembership = Membership::factory()->for($team)->create();
    $noShowMembership = Membership::factory()->for($team)->create();

    DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $plannedMembership->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'attendance_status' => 'planned',
        'actual_starts_at' => null,
        'actual_ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $adjustedMembership->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'attendance_status' => 'adjusted',
        'actual_starts_at' => $start,
        'actual_ends_at' => $start->addHours(3),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $noShowMembership->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'attendance_status' => 'no_show',
        'actual_starts_at' => null,
        'actual_ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Adjusted shift requires actual_ends_at > actual_starts_at
    $membership1 = Membership::factory()->for($team)->create();
    expect(fn () => DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $membership1->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'attendance_status' => 'adjusted',
        'actual_starts_at' => $start,
        'actual_ends_at' => $start,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Adjusted shift requires both actual timestamps to be present
    expect(fn () => DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $membership1->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'attendance_status' => 'adjusted',
        'actual_starts_at' => $start,
        'actual_ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Planned shift must have null actual timestamps
    expect(fn () => DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $membership1->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'attendance_status' => 'planned',
        'actual_starts_at' => $start,
        'actual_ends_at' => $start->addHours(2),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // No-show shift must have null actual timestamps
    expect(fn () => DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $membership1->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'attendance_status' => 'no_show',
        'actual_starts_at' => $start,
        'actual_ends_at' => $start->addHours(2),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Invalid attendance_status value is rejected
    expect(fn () => DB::table('shifts')->insert([
        'slot_id' => $slot->id,
        'membership_id' => $membership1->id,
        'status' => ShiftStatus::Confirmed->value,
        'confirmed_at' => now(),
        'attendance_status' => 'invalid_state',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
