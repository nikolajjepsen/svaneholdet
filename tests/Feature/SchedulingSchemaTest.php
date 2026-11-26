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
