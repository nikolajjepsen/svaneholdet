<?php

use App\Models\Membership;
use App\Models\Season;
use App\Models\SeasonHourGoal;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Carbon\CarbonImmutable;

it('shows the same current-season goal progress on Schedule and My shifts', function () {
    $day = CarbonImmutable::parse('2027-08-05', 'Europe/Copenhagen');
    $this->travelTo($day->setTime(21, 0));

    $team = Team::factory()->create(['settings' => new TeamSettings(defaultHourGoal: 24)]);
    $season = Season::factory()->for($team)->create(['name' => 'Smukfest 2027']);
    $oldSeason = Season::factory()->for($team)->create(['name' => 'Smukfest 2026']);
    $team->update(['current_season_id' => $season->id]);

    $user = User::factory()->create(['locale' => 'en']);
    $membership = Membership::factory()->for($user)->for($team)->create();
    $admin = User::factory()->create();
    $otherMembership = Membership::factory()->for($admin)->for($team)->admin()->create();

    SeasonHourGoal::factory()->for($oldSeason)->for($membership)->create(['hours' => 40]);
    SeasonHourGoal::factory()->for($season)->for($otherMembership)->create(['hours' => 32]);

    $adjustedSlot = Slot::factory()->for($season)->create([
        'starts_at' => $day->setTime(12, 0)->utc(),
        'ends_at' => $day->setTime(16, 0)->utc(),
    ]);
    Shift::factory()->for($membership)->for($adjustedSlot)->adjusted(
        startsAt: $day->setTime(13, 0),
        endsAt: $day->setTime(15, 30),
        recordedBy: $admin,
    )->create();

    $pendingSlot = Slot::factory()->for($season)->create([
        'starts_at' => $day->setTime(15, 0)->utc(),
        'ends_at' => $day->setTime(18, 30)->utc(),
    ]);
    Shift::factory()->for($membership)->for($pendingSlot)->pending()->create();

    $noShowSlot = Slot::factory()->for($season)->create([
        'starts_at' => $day->setTime(19, 0)->utc(),
        'ends_at' => $day->setTime(20, 0)->utc(),
    ]);
    Shift::factory()->for($membership)->for($noShowSlot)->noShow(recordedBy: $admin)->create();
    Shift::factory()->for($otherMembership)->for($noShowSlot)->confirmed()->create();

    $upcomingSlot = Slot::factory()->for($season)->create([
        'starts_at' => $day->addDay()->setTime(10, 0)->utc(),
        'ends_at' => $day->addDay()->setTime(14, 0)->utc(),
    ]);
    Shift::factory()->for($membership)->for($upcomingSlot)->confirmed()->create();

    Shift::factory()->for($membership)->for(Slot::factory()->for($oldSeason))->confirmed()->create();

    $this->actingAs($user);

    $this->get(route('schedule.index'))
        ->assertOk()
        ->assertSeeText('9 h 30 m / 24 h · 3 confirmed, 1 pending');

    $this->get(route('my-shifts.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['9 h 30 m / 24 h · 3 confirmed, 1 pending', 'Planned hours', '9 h', 'Worked hours', '2 h 30 m']);
});

it('shows goal progress or counts alone according to the admin member’s effective goal', function (
    ?int $default,
    ?int $override,
    string $locale,
    string $summary,
) {
    $team = Team::factory()->create(['settings' => new TeamSettings(defaultHourGoal: $default)]);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    $user = User::factory()->create(['locale' => $locale]);
    $membership = Membership::factory()->for($user)->for($team)->admin()->create();

    if ($override !== null) {
        SeasonHourGoal::factory()->for($season)->for($membership)->create(['hours' => $override]);
    }

    $slot = Slot::factory()->for($season)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-06 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2027-08-06 14:00', 'UTC'),
    ]);
    Shift::factory()->for($membership)->for($slot)->confirmed()->create();
    Shift::factory()->for($membership)->for(Slot::factory()->for($season))->rejected()->create();
    Shift::factory()->for($membership)->for(Slot::factory()->for($season))->cancelled()->create();

    $this->actingAs($user);

    foreach (['schedule.index', 'my-shifts.index'] as $route) {
        $this->get(route($route))
            ->assertOk()
            ->assertSeeText($summary)
            ->assertDontSeeText('/ 24 h')
            ->assertDontSeeText('/ 24 t');
    }
})->with([
    'custom goal' => [24, 12, 'en', '4 / 12 h · 1 confirmed, 0 pending'],
    'no default or override' => [null, null, 'en', '1 confirmed, 0 pending'],
    'exempt from a team default' => [24, 0, 'da', '1 bekræftet, 0 afventer'],
]);
