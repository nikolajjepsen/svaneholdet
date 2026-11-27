<?php

use App\Mail\SlotDeletedMail;
use App\Mail\SlotTimeChangedMail;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\User;
use App\Scheduling\Series;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * @return array{0: Team, 1: Season}
 */
function createSeriesTeam(string $name): array
{
    $team = Team::factory()->create([
        'name' => $name,
        'timezone' => 'Europe/Copenhagen',
    ]);
    $season = Season::factory()->for($team)->create(['name' => Season::DEFAULT_NAME]);
    $team->update(['current_season_id' => $season->id]);

    return [$team->fresh(), $season];
}

it('edits only changed fields on upcoming slots in a series, preserving individual edits and skipping started slots', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-03 10:00', 'Europe/Copenhagen'));

    [$teamA, $seasonA] = createSeriesTeam('Bar Team');
    [$teamB] = createSeriesTeam('Stage Team');

    $series = SlotSeries::factory()->for($seasonA)->create([
        'local_start_time' => '08:00',
        'local_end_time' => '16:00',
        'capacity' => 4,
    ]);

    $startedSlot = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-03 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-03 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $customSlot = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 7,
    ]);

    $standardSlot = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'email' => 'alice@example.com', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($teamA)->create();
    Shift::factory()->confirmed()->for($customSlot)->for($adminMembership)->create();

    $volunteer = User::factory()->create(['name' => 'Freja Hansen', 'email' => 'freja@example.com', 'locale' => 'da']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($teamA)->create();
    Membership::factory()->for($volunteer)->for($teamB)->create();
    Shift::factory()->confirmed()->for($startedSlot)->for($volunteerMembership)->create();
    Shift::factory()->confirmed()->for($customSlot)->for($volunteerMembership)->create();
    Shift::factory()->confirmed()->for($standardSlot)->for($volunteerMembership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.series', ['slotSeries' => $series])
        ->assertSet('startTime', '08:00')
        ->assertSet('endTime', '16:00')
        ->assertSet('capacity', 4)
        ->set('startTime', '09:00')
        ->call('saveSeries')
        ->assertHasNoErrors()
        ->assertSet('showWarningModal', false)
        ->assertSee('Slot series updated.');

    expect($startedSlot->fresh()->starts_at->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-03 08:00');
    expect($startedSlot->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-03 16:00');
    expect($startedSlot->fresh()->capacity)->toBe(4);

    expect($customSlot->fresh()->starts_at->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-04 09:00');
    expect($customSlot->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-04 18:00');
    expect($customSlot->fresh()->capacity)->toBe(7);

    expect($standardSlot->fresh()->starts_at->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 09:00');
    expect($standardSlot->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('Y-m-d H:i'))->toBe('2027-08-05 16:00');
    expect($standardSlot->fresh()->capacity)->toBe(4);

    expect($series->fresh()->local_start_time)->toBe('09:00');
    expect($series->fresh()->local_end_time)->toBe('16:00');
    expect($series->fresh()->capacity)->toBe(4);

    Mail::assertSent(SlotTimeChangedMail::class, 2);
    Mail::assertSent(SlotTimeChangedMail::class, fn (SlotTimeChangedMail $mail): bool => $mail->hasTo($volunteer->email) && $mail->slot->is($customSlot) && $mail->locale === 'da');
    Mail::assertSent(SlotTimeChangedMail::class, fn (SlotTimeChangedMail $mail): bool => $mail->hasTo($volunteer->email) && $mail->slot->is($standardSlot) && $mail->locale === 'da');
    Mail::assertNotSent(SlotTimeChangedMail::class, fn (SlotTimeChangedMail $mail): bool => $mail->slot->is($startedSlot));
    Mail::assertNotSent(SlotTimeChangedMail::class, fn (SlotTimeChangedMail $mail): bool => $mail->hasTo($admin->email));

    Mail::fake();

    Livewire::test('pages::schedule.series', ['slotSeries' => $series->fresh()])
        ->set('capacity', 5)
        ->call('saveSeries')
        ->assertHasNoErrors();

    expect($startedSlot->fresh()->capacity)->toBe(4);
    expect($customSlot->fresh()->capacity)->toBe(5);
    expect($customSlot->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('18:00');
    expect($standardSlot->fresh()->capacity)->toBe(5);
    expect($standardSlot->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('16:00');
    Mail::assertNothingSent();
});

it('blocks a series time change as a whole when any confirmed member overlaps another team and lists all clashes', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-01 10:00', 'Europe/Copenhagen'));

    [$teamA, $seasonA] = createSeriesTeam('Bar Team');
    [$teamB, $seasonB] = createSeriesTeam('Stage Team');

    $series = SlotSeries::factory()->for($seasonA)->create([
        'local_start_time' => '08:00',
        'local_end_time' => '12:00',
        'capacity' => 4,
    ]);

    $slotDay1 = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $slotDay2 = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $otherTeamSlotDay2 = Slot::factory()->for($seasonB)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-05 14:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();

    $volunteer = User::factory()->create(['name' => 'Bob Volunteer', 'email' => 'bob@example.com', 'locale' => 'en']);
    $membershipA = Membership::factory()->for($volunteer)->for($teamA)->create();
    $membershipB = Membership::factory()->for($volunteer)->for($teamB)->create();

    Shift::factory()->confirmed()->for($slotDay2)->for($membershipA)->create();
    Shift::factory()->confirmed()->for($otherTeamSlotDay2)->for($membershipB)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.series', ['slotSeries' => $series])
        ->set('endTime', '16:00')
        ->call('saveSeries')
        ->assertHasErrors(['times'])
        ->assertSet('showWarningModal', false)
        ->assertSee('Bob Volunteer')
        ->assertSee('Stage Team');

    expect($slotDay1->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('12:00');
    expect($slotDay2->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('12:00');
    expect($series->fresh()->local_end_time)->toBe('12:00');
    Mail::assertNothingSent();
});

it('warns on same-team overlap and over-capacity when editing a series and saves once accepted', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-01 10:00', 'Europe/Copenhagen'));

    [$teamA, $seasonA] = createSeriesTeam('Bar Team');
    [$teamB] = createSeriesTeam('Stage Team');

    $series = SlotSeries::factory()->for($seasonA)->create([
        'local_start_time' => '08:00',
        'local_end_time' => '12:00',
        'capacity' => 3,
    ]);

    $seriesSlot = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 12:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $laterSameTeamSlot = Slot::factory()->for($seasonA)->create([
        'starts_at' => CarbonImmutable::parse('2027-08-04 14:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 18:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 3,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();

    $volunteer1 = User::factory()->create(['name' => 'Bob Volunteer', 'email' => 'bob@example.com', 'locale' => 'en']);
    $membership1 = Membership::factory()->for($volunteer1)->for($teamA)->create();
    Membership::factory()->for($volunteer1)->for($teamB)->create();
    Shift::factory()->confirmed()->for($seriesSlot)->for($membership1)->create();
    Shift::factory()->confirmed()->for($laterSameTeamSlot)->for($membership1)->create();

    $volunteer2 = User::factory()->create(['name' => 'Clara Volunteer', 'email' => 'clara@example.com', 'locale' => 'en']);
    $membership2 = Membership::factory()->for($volunteer2)->for($teamA)->create();
    Shift::factory()->confirmed()->for($seriesSlot)->for($membership2)->create();

    $this->actingAs($admin);

    $component = Livewire::test('pages::schedule.series', ['slotSeries' => $series])
        ->set('endTime', '15:00')
        ->set('capacity', 1)
        ->call('saveSeries')
        ->assertHasNoErrors()
        ->assertSet('showWarningModal', true)
        ->assertSee('over capacity')
        ->assertSee('Bob Volunteer');

    expect($seriesSlot->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('12:00');
    expect($seriesSlot->fresh()->capacity)->toBe(3);
    Mail::assertNothingSent();

    $component
        ->call('acceptWarnings')
        ->assertHasNoErrors()
        ->assertSet('showWarningModal', false)
        ->assertSee('Slot series updated.');

    expect($seriesSlot->fresh()->ends_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('15:00');
    expect($seriesSlot->fresh()->capacity)->toBe(1);

    Mail::assertSent(SlotTimeChangedMail::class, 2);
});

it('deletes a whole series when none of its slots have started, respecting the email checkbox and skipping the admin', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-01 10:00', 'Europe/Copenhagen'));

    [$teamA, $seasonA] = createSeriesTeam('Bar Team');
    [$teamB] = createSeriesTeam('Stage Team');

    $series = SlotSeries::factory()->for($seasonA)->create([
        'local_start_time' => '08:00',
        'local_end_time' => '16:00',
        'capacity' => 4,
    ]);

    $slot1 = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $slot2 = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
        'capacity' => 4,
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'email' => 'alice@example.com', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    $adminMembership = Membership::factory()->admin()->for($admin)->for($teamA)->create();
    Shift::factory()->confirmed()->for($slot1)->for($adminMembership)->create();

    $volunteer = User::factory()->create(['name' => 'Freja Hansen', 'email' => 'freja@example.com', 'locale' => 'da']);
    $volunteerMembership = Membership::factory()->for($volunteer)->for($teamA)->create();
    Membership::factory()->for($volunteer)->for($teamB)->create();
    Shift::factory()->confirmed()->for($slot1)->for($volunteerMembership)->create();
    Shift::factory()->pending()->for($slot2)->for($volunteerMembership)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.series', ['slotSeries' => $series])
        ->assertSet('notifyMembersOnDelete', true)
        ->call('openDeleteModal')
        ->assertSet('showDeleteModal', true)
        ->assertSee('2 confirmed')
        ->assertSee('1 pending')
        ->call('deleteSeries')
        ->assertRedirect(route('schedule.index'));

    expect(SlotSeries::query()->find($series->id))->toBeNull();
    expect(Slot::query()->whereIn('id', [$slot1->id, $slot2->id])->exists())->toBeFalse();

    Mail::assertSent(SlotDeletedMail::class, 2);
    Mail::assertSent(SlotDeletedMail::class, fn (SlotDeletedMail $mail): bool => $mail->hasTo($volunteer->email) && $mail->locale === 'da');
    Mail::assertNotSent(SlotDeletedMail::class, fn (SlotDeletedMail $mail): bool => $mail->hasTo($admin->email));
});

it('forbids deleting a series if any of its slots has already started', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-08-04 10:00', 'Europe/Copenhagen'));

    [$teamA, $seasonA] = createSeriesTeam('Bar Team');
    [$teamB] = createSeriesTeam('Stage Team');

    $series = SlotSeries::factory()->for($seasonA)->create();

    $startedSlot = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-04 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-04 16:00', 'Europe/Copenhagen')->utc(),
    ]);

    $futureSlot = Slot::factory()->for($seasonA)->create([
        'slot_series_id' => $series->id,
        'starts_at' => CarbonImmutable::parse('2027-08-05 08:00', 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen')->utc(),
    ]);

    $admin = User::factory()->create(['name' => 'Alice Admin', 'locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($admin)->for($teamA)->create();
    Membership::factory()->for($admin)->for($teamB)->create();

    $this->actingAs($admin);

    Livewire::test('pages::schedule.series', ['slotSeries' => $series])
        ->call('deleteSeries')
        ->assertHasErrors(['series']);

    expect(SlotSeries::query()->find($series->id))->not->toBeNull();
    expect(Slot::query()->find($startedSlot->id))->not->toBeNull();
    expect(Slot::query()->find($futureSlot->id))->not->toBeNull();
    Mail::assertNothingSent();
});

it('forbids volunteers and admins of another team from viewing, editing, or deleting a slot series', function () {
    Mail::fake();

    [$teamA, $seasonA] = createSeriesTeam('Bar Team');
    [$teamB, $seasonB] = createSeriesTeam('Stage Team');

    $seriesB = SlotSeries::factory()->for($seasonB)->create();
    Slot::factory()->for($seasonB)->create([
        'slot_series_id' => $seriesB->id,
        'starts_at' => now()->addDays(5)->setTime(8, 0),
        'ends_at' => now()->addDays(5)->setTime(16, 0),
    ]);

    $adminA = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamA->id]);
    Membership::factory()->admin()->for($adminA)->for($teamA)->create();

    $volunteerB = User::factory()->create(['locale' => 'en', 'last_chosen_team_id' => $teamB->id]);
    Membership::factory()->for($volunteerB)->for($teamB)->create();

    $this->actingAs($adminA)
        ->get(route('schedule.series.show', $seriesB))
        ->assertForbidden();

    $this->actingAs($volunteerB)
        ->get(route('schedule.series.show', $seriesB))
        ->assertForbidden();

    expect(fn () => app(Series::class)->update($seriesB, $adminA, '09:00', '17:00', 5))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(Series::class)->delete($seriesB, $adminA, notifyMembers: true))
        ->toThrow(AuthorizationException::class);

    expect(SlotSeries::query()->find($seriesB->id))->not->toBeNull();
    Mail::assertNothingSent();
});
