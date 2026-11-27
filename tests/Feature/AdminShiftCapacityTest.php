<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\SlotSeries;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Livewire\Livewire;

function createAdminShiftTeam(?TeamSettings $settings = null): Season
{
    $team = Team::factory()->create([
        'timezone' => 'Europe/Copenhagen',
        'settings' => $settings ?? new TeamSettings,
    ]);

    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);

    return $season;
}

it('confirms an admin signup on a full regular slot without warnings', function (bool $requiresConfirmation) {
    $season = createAdminShiftTeam(new TeamSettings(shiftsRequireConfirmation: $requiresConfirmation));
    $slot = Slot::factory()->for($season)->create(['capacity' => 6]);
    $admin = Membership::factory()->admin()->for(User::factory()->create(['locale' => 'en']))
        ->for($season->team)->create();

    Membership::factory()->for($season->team)->count(6)->create()->each(
        fn (Membership $member) => Shift::factory()->confirmed()->for($slot)->for($member)->create(),
    );

    $this->actingAs($admin->user);

    Livewire::test('pages::schedule.index')
        ->assertSeeHtml('data-test="sign-up-slot-'.$slot->id.'"')
        ->call('signUp', $slot->id)
        ->assertHasNoErrors()
        ->assertSee('You are signed up for this slot.')
        ->assertSee('6 / 6')
        ->assertDontSee('7 / 6');

    $this->assertDatabaseHas(Shift::class, [
        'slot_id' => $slot->id,
        'membership_id' => $admin->id,
        'status' => ShiftStatus::Confirmed,
    ]);
})->with(['confirmation disabled' => false, 'confirmation enabled' => true]);

it('lets a volunteer take the last place on a regular slot already staffed by an admin', function () {
    $season = createAdminShiftTeam();
    $slot = Slot::factory()->for($season)->create(['capacity' => 1]);
    $admin = Membership::factory()->admin()->for($season->team)->create();
    Shift::factory()->confirmed()->for($slot)->for($admin)->create();
    $volunteer = Membership::factory()->for(User::factory()->create(['locale' => 'en']))
        ->for($season->team)->create();

    $this->actingAs($volunteer->user);

    Livewire::test('pages::schedule.index')
        ->assertSee('0 / 1')
        ->call('signUp', $slot->id)
        ->assertHasNoErrors()
        ->assertSee('You are signed up for this slot.')
        ->assertSee('1 / 1');

    $this->assertDatabaseHas(Shift::class, [
        'slot_id' => $slot->id,
        'membership_id' => $volunteer->id,
        'status' => ShiftStatus::Confirmed,
    ]);
});

it('adds an admin to a full regular slot without an over-capacity warning', function (bool $pending) {
    $season = createAdminShiftTeam();
    $slot = Slot::factory()->for($season)->create(['capacity' => 1]);
    $admin = Membership::factory()->admin()->for(User::factory()->create(['locale' => 'en']))
        ->for($season->team)->create();
    $volunteer = Membership::factory()->for($season->team)->create();
    Shift::factory()->confirmed()->for($slot)->for($volunteer)->create();
    $pendingShift = $pending ? Shift::factory()->pending()->for($slot)->for($admin)->create() : null;

    $this->actingAs($admin->user);

    $page = Livewire::test('pages::schedule.slot', ['slot' => $slot]);

    if ($pendingShift !== null) {
        $page->call('confirmShift', $pendingShift->id);
    } else {
        $page->set('assignMembershipId', $admin->id)->call('assignMember');
    }

    $page->assertHasNoErrors()->assertSet('showOverCapacityModal', false);

    $this->assertDatabaseHas(Shift::class, [
        'slot_id' => $slot->id,
        'membership_id' => $admin->id,
        'status' => ShiftStatus::Confirmed,
    ]);
})->with(['assigns an admin' => false, 'confirms an existing pending admin shift' => true]);

it('lowers regular slot capacity to the number of confirmed volunteers without warnings', function (bool $editSeries) {
    $season = createAdminShiftTeam();
    $series = SlotSeries::factory()->for($season)->create(['capacity' => 2]);
    $slot = Slot::factory()->for($season)->for($series, 'series')->create(['capacity' => 2]);
    $admin = Membership::factory()->admin()->for(User::factory()->create(['locale' => 'en']))
        ->for($season->team)->create();
    $volunteer = Membership::factory()->for($season->team)->create();
    Shift::factory()->confirmed()->for($slot)->for($admin)->create();
    Shift::factory()->confirmed()->for($slot)->for($volunteer)->create();

    $this->actingAs($admin->user);

    if ($editSeries) {
        Livewire::test('pages::schedule.series', ['slotSeries' => $series])
            ->set('capacity', 1)
            ->call('saveSeries')
            ->assertHasNoErrors()
            ->assertSet('showWarningModal', false)
            ->assertSee('1 / 1');

        expect($series->fresh()->capacity)->toBe(1);
    } else {
        Livewire::test('pages::schedule.slot', ['slot' => $slot])
            ->set('capacity', 1)
            ->call('updateCapacity')
            ->assertHasNoErrors()
            ->assertSet('showOverCapacityModal', false)
            ->assertSee('1 / 1');
    }

    expect($slot->fresh()->capacity)->toBe(1);
})->with(['single slot' => false, 'slot series' => true]);

it('shows confirmed admins on the schedule even when volunteer names are hidden', function () {
    $season = createAdminShiftTeam(new TeamSettings(showConfirmedVolunteerNames: false));
    $slot = Slot::factory()->for($season)->create(['capacity' => 1]);
    $admin = Membership::factory()->admin()->for(User::factory()->create(['name' => 'Astrid Andersen']))
        ->for($season->team)->create();
    $pendingAdmin = Membership::factory()->admin()->for(User::factory()->create(['name' => 'Pernille Larsen']))
        ->for($season->team)->create();
    $volunteer = Membership::factory()->for(User::factory()->create(['name' => 'Viktor Hansen']))
        ->for($season->team)->create();
    Shift::factory()->confirmed()->for($slot)->for($admin)->create();
    Shift::factory()->pending()->for($slot)->for($pendingAdmin)->create();
    Shift::factory()->confirmed()->for($slot)->for($volunteer)->create();
    $viewer = Membership::factory()->for(User::factory()->create(['locale' => 'en']))
        ->for($season->team)->create();

    $this->actingAs($viewer->user)
        ->get(route('schedule.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['1 / 1', 'Admins', 'Astrid'])
        ->assertDontSee('Astrid Andersen')
        ->assertDontSee('Pernille')
        ->assertDontSee('Viktor')
        ->assertSeeHtml('data-test="full-slot-'.$slot->id.'"');
});

it('shows admins on day overview without counting them toward capacity or dropping them from unique headcount', function () {
    $season = createAdminShiftTeam();
    $firstSlot = Slot::factory()->for($season)->create(['capacity' => 1]);
    $secondSlot = Slot::factory()->for($season)->create([
        'starts_at' => $firstSlot->ends_at,
        'ends_at' => $firstSlot->ends_at->addHours(2),
        'capacity' => 1,
    ]);
    $admin = Membership::factory()->admin()->for(User::factory()->create(['locale' => 'en']))
        ->for($season->team)->create();
    $supervisor = Membership::factory()->admin()->for(User::factory()->create(['name' => 'Astrid Andersen']))
        ->for($season->team)->create();
    $volunteer = Membership::factory()->for($season->team)->create();
    Shift::factory()->confirmed()->for($firstSlot)->for($volunteer)->create();
    Shift::factory()->confirmed()->for($firstSlot)->for($supervisor)->create();
    Shift::factory()->confirmed()->for($secondSlot)->for($supervisor)->create();

    $this->actingAs($admin->user)
        ->get(route('day-overview.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['Unique confirmed members', '2', 'Admins', 'Astrid', '1 / 1', 'Admins', 'Astrid', '0 / 1'])
        ->assertDontSeeText('2 / 1');
});

it('updates regular slot capacity and over-capacity badges when a member changes role', function () {
    $season = createAdminShiftTeam();
    $slot = Slot::factory()->for($season)->create(['capacity' => 1]);
    $manager = Membership::factory()->admin()->for(User::factory()->create(['locale' => 'en']))
        ->for($season->team)->create();
    $seniorVolunteer = Membership::factory()->for($season->team)->create();
    $promotedMember = Membership::factory()->for(User::factory()->create(['name' => 'Katrine Hansen']))
        ->for($season->team)->create();
    $supervisor = Membership::factory()->admin()->for(User::factory()->create(['name' => 'Astrid Andersen']))
        ->for($season->team)->create();

    Shift::factory()->confirmed()->for($slot)->for($seniorVolunteer)->create(['confirmed_at' => now()->subHours(3)]);
    $changedShift = Shift::factory()->confirmed()->for($slot)->for($promotedMember)->create(['confirmed_at' => now()->subHours(2)]);
    $adminShift = Shift::factory()->confirmed()->for($slot)->for($supervisor)->create(['confirmed_at' => now()->subHour()]);

    $this->actingAs($manager->user);
    $slotUrl = route('schedule.slots.show', $slot);

    $this->get($slotUrl)->assertOk()
        ->assertSee('2 / 1')
        ->assertSeeTextInOrder(['Volunteers', 'Katrine Hansen', 'Admins', 'Astrid Andersen'])
        ->assertSee('over-capacity-badge-'.$changedShift->id)
        ->assertDontSee('over-capacity-badge-'.$adminShift->id);

    $membersPage = Livewire::test('pages::members.index');
    $membersPage->call('promote', $promotedMember->id)->assertHasNoErrors();

    $this->get($slotUrl)->assertOk()
        ->assertSee('1 / 1')
        ->assertSeeTextInOrder(['Volunteers', 'Admins', 'Katrine Hansen', 'Astrid Andersen'])
        ->assertDontSee('over-capacity-badge-'.$changedShift->id);

    $membersPage->call('demote', $promotedMember->id)->assertHasNoErrors();

    $this->get($slotUrl)->assertOk()
        ->assertSee('2 / 1')
        ->assertSee('over-capacity-badge-'.$changedShift->id)
        ->assertDontSee('over-capacity-badge-'.$adminShift->id);
});
