<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\Rollout;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

it('enforces two-team admin authorization for slot series and links slots to their series', function () {
    $teamA = Team::factory()->create(['name' => 'Team A', 'timezone' => 'Europe/Copenhagen']);
    $seasonA = Season::factory()->for($teamA)->create(['name' => Season::DEFAULT_NAME]);
    $teamA->update(['current_season_id' => $seasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team B', 'timezone' => 'Europe/Copenhagen']);
    $seasonB = Season::factory()->for($teamB)->create(['name' => Season::DEFAULT_NAME]);
    $teamB->update(['current_season_id' => $seasonB->id]);

    $adminA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $volunteerA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteerA)->for($teamA)->create();

    $adminB = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminB)->for($teamB)->create();

    $seriesA = SlotSeries::factory()->for($seasonA)->create([
        'local_start_time' => '08:00',
        'local_end_time' => '16:00',
        'capacity' => 6,
    ]);
    $slotA = Slot::factory()->for($seasonA)->create(['slot_series_id' => $seriesA->id]);

    $seriesA->load('season', 'slots');
    $slotA->load('series');
    $seasonA->load('slotSeries');

    expect($seriesA->slots)->toHaveCount(1)
        ->and($slotA->series?->is($seriesA))->toBeTrue()
        ->and($seasonA->slotSeries)->toHaveCount(1);

    expect(Gate::forUser($adminA)->allows('viewAny', [SlotSeries::class, $teamA]))->toBeTrue()
        ->and(Gate::forUser($adminA)->allows('create', [SlotSeries::class, $seasonA]))->toBeTrue()
        ->and(Gate::forUser($adminA)->allows('view', $seriesA))->toBeTrue()
        ->and(Gate::forUser($adminA)->allows('update', $seriesA))->toBeTrue()
        ->and(Gate::forUser($adminA)->allows('delete', $seriesA))->toBeTrue()
        ->and(Gate::forUser($volunteerA)->allows('viewAny', [SlotSeries::class, $teamA]))->toBeFalse()
        ->and(Gate::forUser($volunteerA)->allows('create', [SlotSeries::class, $seasonA]))->toBeFalse()
        ->and(Gate::forUser($adminB)->allows('viewAny', [SlotSeries::class, $teamA]))->toBeFalse()
        ->and(Gate::forUser($adminB)->allows('create', [SlotSeries::class, $seasonA]))->toBeFalse()
        ->and(Gate::forUser($adminB)->allows('update', $seriesA))->toBeFalse()
        ->and(Gate::forUser($adminB)->allows('delete', $seriesA))->toBeFalse();
});

it('rolls out multiple dates with two intervals into two slot series using the team timezone', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $dates = ['2027-08-04', '2027-08-05', '2027-08-06', '2027-08-08'];
    $intervals = [
        ['start' => '08:00', 'end' => '16:00', 'capacity' => 6],
        ['start' => '20:00', 'end' => '08:00', 'capacity' => 4],
    ];

    $rollout = app(Rollout::class);
    $preview = $rollout->preview($season, $dates, $intervals);

    expect($preview->slotCount)->toBe(8)
        ->and($preview->dayCount)->toBe(4)
        ->and($preview->duplicateCount)->toBe(0)
        ->and($preview->hasDuplicates())->toBeFalse();

    $createdSeries = $rollout->save($season, $admin, $dates, $intervals);

    expect($createdSeries)->toHaveCount(2)
        ->and(SlotSeries::query()->where('season_id', $season->id)->count())->toBe(2)
        ->and(Slot::query()->where('season_id', $season->id)->count())->toBe(8);

    $daySeries = $createdSeries->firstWhere('local_start_time', '08:00');
    $nightSeries = $createdSeries->firstWhere('local_start_time', '20:00');

    expect($daySeries)->not->toBeNull()
        ->and($daySeries->local_end_time)->toBe('16:00')
        ->and($daySeries->capacity)->toBe(6)
        ->and($daySeries->slots()->count())->toBe(4);

    expect($nightSeries)->not->toBeNull()
        ->and($nightSeries->local_end_time)->toBe('08:00')
        ->and($nightSeries->capacity)->toBe(4)
        ->and($nightSeries->slots()->count())->toBe(4);

    $firstDaySlot = $daySeries->slots()->orderBy('starts_at')->first();
    // 2027-08-04 in Europe/Copenhagen (CEST = UTC+2)
    expect($firstDaySlot->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-08-04 06:00')
        ->and($firstDaySlot->ends_at->utc()->format('Y-m-d H:i'))->toBe('2027-08-04 14:00')
        ->and($firstDaySlot->capacity)->toBe(6);

    $firstNightSlot = $nightSeries->slots()->orderBy('starts_at')->first();
    // 20:00 on Aug 4 to 08:00 on Aug 5 in CEST (UTC+2)
    expect($firstNightSlot->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-08-04 18:00')
        ->and($firstNightSlot->ends_at->utc()->format('Y-m-d H:i'))->toBe('2027-08-05 06:00')
        ->and($firstNightSlot->capacity)->toBe(4);
});

it('detects exact duplicates in the current season only and still allows saving', function () {
    $teamA = Team::factory()->create(['name' => 'Team A', 'timezone' => 'Europe/Copenhagen']);
    $seasonA = Season::factory()->for($teamA)->create(['name' => Season::DEFAULT_NAME]);
    $oldSeasonA = Season::factory()->for($teamA)->create(['name' => 'Smukfest 2026']);
    $teamA->update(['current_season_id' => $seasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team B', 'timezone' => 'Europe/Copenhagen']);
    $seasonB = Season::factory()->for($teamB)->create(['name' => Season::DEFAULT_NAME]);
    $teamB->update(['current_season_id' => $seasonB->id]);

    $adminA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    // Two exact duplicates in current season A
    Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
    ]);
    Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 20:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 08:00', 'Europe/Copenhagen')->utc(),
    ]);

    // Same start/end in another team and in an older season must NOT count as duplicates
    Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 16:00', 'Europe/Copenhagen')->utc(),
    ]);
    Slot::factory()->for($oldSeasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-07 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-07 16:00', 'Europe/Copenhagen')->utc(),
    ]);

    // Different end time in season A must NOT count as an exact duplicate
    Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 14:00', 'Europe/Copenhagen')->utc(),
    ]);

    $dates = ['2027-08-04', '2027-08-05', '2027-08-06', '2027-08-07'];
    $intervals = [
        ['start' => '08:00', 'end' => '16:00', 'capacity' => 4],
        ['start' => '20:00', 'end' => '08:00', 'capacity' => 3],
    ];

    $rollout = app(Rollout::class);
    $preview = $rollout->preview($seasonA, $dates, $intervals);

    expect($preview->slotCount)->toBe(8)
        ->and($preview->dayCount)->toBe(4)
        ->and($preview->duplicateCount)->toBe(2)
        ->and($preview->hasDuplicates())->toBeTrue()
        ->and($preview->duplicates)->toHaveCount(2);

    $rollout->save($seasonA, $adminA, $dates, $intervals);

    // 3 pre-existing slots in seasonA + 8 newly rolled out slots = 11
    expect(Slot::query()->where('season_id', $seasonA->id)->count())->toBe(11)
        ->and(SlotSeries::query()->where('season_id', $seasonA->id)->count())->toBe(2);
});

it('saves nothing when a rollout fails partway through', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $createdCount = 0;
    Slot::creating(function () use (&$createdCount): void {
        $createdCount++;
        if ($createdCount === 3) {
            throw new RuntimeException('Simulated database failure.');
        }
    });

    try {
        expect(fn () => app(Rollout::class)->save(
            season: $season,
            actor: $admin,
            dates: ['2027-08-04', '2027-08-05'],
            intervals: [
                ['start' => '08:00', 'end' => '16:00', 'capacity' => 4],
                ['start' => '16:00', 'end' => '00:00', 'capacity' => 4],
            ],
        ))->toThrow(RuntimeException::class, 'Simulated database failure.');
    } finally {
        Slot::flushEventListeners();
    }

    expect(SlotSeries::query()->where('season_id', $season->id)->count())->toBe(0)
        ->and(Slot::query()->where('season_id', $season->id)->count())->toBe(0);
});

it('forbids volunteers and admins of another team from saving a rollout', function () {
    $teamA = Team::factory()->create(['name' => 'Team A', 'timezone' => 'Europe/Copenhagen']);
    $seasonA = Season::factory()->for($teamA)->create(['name' => Season::DEFAULT_NAME]);
    $teamA->update(['current_season_id' => $seasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team B', 'timezone' => 'Europe/Copenhagen']);
    $seasonB = Season::factory()->for($teamB)->create(['name' => Season::DEFAULT_NAME]);
    $teamB->update(['current_season_id' => $seasonB->id]);

    $volunteerA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteerA)->for($teamA)->create();

    $adminB = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminB)->for($teamB)->create();

    $rollout = app(Rollout::class);

    expect(fn () => $rollout->save($seasonA, $volunteerA, ['2027-08-04'], [['start' => '08:00', 'end' => '16:00', 'capacity' => 2]]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $rollout->save($seasonA, $adminB, ['2027-08-04'], [['start' => '08:00', 'end' => '16:00', 'capacity' => 2]]))
        ->toThrow(AuthorizationException::class);

    expect(SlotSeries::query()->count())->toBe(0)
        ->and(Slot::query()->count())->toBe(0);
});

it('only allows admins of the current team to open the rollout page', function () {
    $teamA = Team::factory()->create(['name' => 'Team A', 'timezone' => 'Europe/Copenhagen']);
    $seasonA = Season::factory()->for($teamA)->create(['name' => Season::DEFAULT_NAME]);
    $teamA->update(['current_season_id' => $seasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team B', 'timezone' => 'Europe/Copenhagen']);
    $seasonB = Season::factory()->for($teamB)->create(['name' => Season::DEFAULT_NAME]);
    $teamB->update(['current_season_id' => $seasonB->id]);

    $adminA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $volunteerA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($volunteerA)->for($teamA)->create();

    $adminB = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminB)->for($teamB)->create();

    $this->actingAs($adminA)
        ->get(route('schedule.rollout'))
        ->assertOk()
        ->assertSee('Roll out slots')
        ->assertSee('Team A');

    $this->actingAs($volunteerA)
        ->get(route('schedule.rollout'))
        ->assertForbidden();

    $this->actingAs($adminB)
        ->get(route('schedule.rollout'))
        ->assertOk()
        ->assertSee('Team B')
        ->assertDontSee('Team A');
});

it('previews planned slots with duplicate warning and saves through the rollout page', function () {
    $teamA = Team::factory()->create(['name' => 'Team A', 'timezone' => 'Europe/Copenhagen']);
    $seasonA = Season::factory()->for($teamA)->create(['name' => Season::DEFAULT_NAME]);
    $teamA->update(['current_season_id' => $seasonA->id]);

    $teamB = Team::factory()->create(['name' => 'Team B', 'timezone' => 'Europe/Copenhagen']);
    $seasonB = Season::factory()->for($teamB)->create(['name' => Season::DEFAULT_NAME]);
    $teamB->update(['current_season_id' => $seasonB->id]);

    $adminA = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    // Two existing exact duplicates in Team A's current season
    Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
    ]);
    Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 20:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 08:00', 'Europe/Copenhagen')->utc(),
    ]);

    // Same times in Team B should not affect Team A's duplicate count
    Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-06 16:00', 'Europe/Copenhagen')->utc(),
    ]);

    $this->actingAs($adminA);

    Livewire::test('pages::schedule.rollout')
        ->call('toggleDate', '2027-08-04')
        ->call('toggleDate', '2027-08-05')
        ->call('toggleDate', '2027-08-06')
        ->call('toggleDate', '2027-08-07')
        ->set('intervals.0.start', '08:00')
        ->set('intervals.0.end', '16:00')
        ->set('intervals.0.capacity', 6)
        ->call('addInterval')
        ->set('intervals.1.start', '20:00')
        ->set('intervals.1.end', '08:00')
        ->set('intervals.1.capacity', 4)
        ->assertSee('8 slots on 4 days, 2 already exist')
        ->assertSee('Ends next day')
        ->assertSee('Already exists')
        ->call('save')
        ->assertRedirect(route('schedule.index'));

    expect(SlotSeries::query()->where('season_id', $seasonA->id)->count())->toBe(2)
        ->and(Slot::query()->where('season_id', $seasonA->id)->count())->toBe(10)
        ->and(SlotSeries::query()->where('season_id', $seasonB->id)->count())->toBe(0);
});

it('validates dates and intervals on the rollout page', function () {
    $team = Team::factory()->create(['name' => 'Bar Team', 'timezone' => 'Europe/Copenhagen']);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    $admin = User::factory()->create(['locale' => 'en']);
    Membership::factory()->admin()->for($admin)->for($team)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.rollout')
        ->call('save')
        ->assertHasErrors(['selectedDates']);

    Livewire::test('pages::schedule.rollout')
        ->call('toggleDate', '2027-08-04')
        ->set('intervals.0.start', '08:00')
        ->set('intervals.0.end', '08:00')
        ->set('intervals.0.capacity', 0)
        ->call('save')
        ->assertHasErrors(['intervals.0.end', 'intervals.0.capacity']);

    expect(SlotSeries::query()->count())->toBe(0)
        ->and(Slot::query()->count())->toBe(0);
});
