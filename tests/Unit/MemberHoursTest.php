<?php

use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\Slot;
use App\Scheduling\MemberHours;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function makeMemberHoursShift(
    string $startsAt,
    string $endsAt,
    ShiftStatus $status = ShiftStatus::Confirmed,
    AttendanceStatus $attendanceStatus = AttendanceStatus::Planned,
    ?string $actualStartsAt = null,
    ?string $actualEndsAt = null,
): Shift {
    $slot = new Slot([
        'starts_at' => CarbonImmutable::parse($startsAt, 'Europe/Copenhagen')->utc(),
        'ends_at' => CarbonImmutable::parse($endsAt, 'Europe/Copenhagen')->utc(),
    ]);

    $shift = new Shift([
        'status' => $status,
        'attendance_status' => $attendanceStatus,
        'actual_starts_at' => $actualStartsAt !== null
            ? CarbonImmutable::parse($actualStartsAt, 'Europe/Copenhagen')->utc()
            : null,
        'actual_ends_at' => $actualEndsAt !== null
            ? CarbonImmutable::parse($actualEndsAt, 'Europe/Copenhagen')->utc()
            : null,
    ]);

    $shift->setRelation('slot', $slot);

    return $shift;
}

it('calculates planned hours from confirmed shifts including no-show and excludes non-confirmed shifts', function () {
    $calculator = new MemberHours;
    $now = CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen');

    $shifts = collect([
        makeMemberHoursShift('2027-08-05 10:00', '2027-08-05 14:00', ShiftStatus::Confirmed),
        makeMemberHoursShift('2027-08-05 16:00', '2027-08-05 18:00', ShiftStatus::Confirmed, AttendanceStatus::NoShow),
        makeMemberHoursShift('2027-08-05 18:00', '2027-08-05 22:00', ShiftStatus::Pending),
        makeMemberHoursShift('2027-08-06 08:00', '2027-08-06 12:00', ShiftStatus::Rejected),
        makeMemberHoursShift('2027-08-06 14:00', '2027-08-06 18:00', ShiftStatus::Cancelled),
    ]);

    $summary = $calculator->summarize($shifts, $now);

    expect($summary->plannedMinutes)->toBe(360)
        ->and($summary->plannedHours())->toBe(6.0)
        ->and($summary->workedMinutes)->toBe(240)
        ->and($summary->workedHours())->toBe(4.0);
});

it('deduplicates overlapping intervals for both planned and worked hours', function () {
    $calculator = new MemberHours;
    $now = CarbonImmutable::parse('2027-08-06 12:00', 'Europe/Copenhagen');

    // Planned: [10:00, 14:00) U [12:00, 16:00) U [13:00, 15:00) = [10:00, 16:00) = 6h (360m)
    // Worked:  [10:00, 14:00) U [12:30, 15:45) (adjusted) U [13:00, 15:00) (no-show = 0)
    //        = [10:00, 15:45) = 5h 45m (345m)
    $shifts = collect([
        makeMemberHoursShift('2027-08-05 10:00', '2027-08-05 14:00', ShiftStatus::Confirmed),
        makeMemberHoursShift(
            '2027-08-05 12:00',
            '2027-08-05 16:00',
            ShiftStatus::Confirmed,
            AttendanceStatus::Adjusted,
            '2027-08-05 12:30',
            '2027-08-05 15:45',
        ),
        makeMemberHoursShift('2027-08-05 13:00', '2027-08-05 15:00', ShiftStatus::Confirmed, AttendanceStatus::NoShow),
    ]);

    $summary = $calculator->summarize($shifts, $now);

    expect($summary->plannedMinutes)->toBe(360)
        ->and($summary->workedMinutes)->toBe(345)
        ->and($summary->workedHours())->toBe(5.75);
});

it('counts ended shifts and explicitly adjusted future shifts in worked hours while excluding unedited future shifts', function () {
    $calculator = new MemberHours;
    $now = CarbonImmutable::parse('2027-08-05 16:00', 'Europe/Copenhagen');

    $shifts = collect([
        // Ended at exact cutoff (16:00 <= 16:00) -> counts 4h (240m)
        makeMemberHoursShift('2027-08-05 12:00', '2027-08-05 16:00', ShiftStatus::Confirmed),
        // Future unedited shift -> excluded from worked, included in planned (4h)
        makeMemberHoursShift('2027-08-06 10:00', '2027-08-06 14:00', ShiftStatus::Confirmed),
        // Future shift explicitly adjusted -> included in worked immediately (3h 15m = 195m), planned 4h
        makeMemberHoursShift(
            '2027-08-07 12:00',
            '2027-08-07 16:00',
            ShiftStatus::Confirmed,
            AttendanceStatus::Adjusted,
            '2027-08-07 12:30',
            '2027-08-07 15:45',
        ),
        // Future shift marked No-show -> 0 worked, 2h planned
        makeMemberHoursShift('2027-08-08 10:00', '2027-08-08 12:00', ShiftStatus::Confirmed, AttendanceStatus::NoShow),
    ]);

    $summary = $calculator->summarize($shifts, $now);

    expect($summary->plannedMinutes)->toBe(840)
        ->and($summary->workedMinutes)->toBe(435)
        ->and($summary->workedHours())->toBe(7.25);
});

it('calculates per-shift worked minutes accurately for shortened, no-show, ended, and future shifts', function () {
    $calculator = new MemberHours;
    $now = CarbonImmutable::parse('2027-08-05 15:00', 'Europe/Copenhagen');

    $shortened = makeMemberHoursShift(
        '2027-08-05 12:00',
        '2027-08-05 16:00',
        ShiftStatus::Confirmed,
        AttendanceStatus::Adjusted,
        '2027-08-05 12:30',
        '2027-08-05 15:45',
    );
    $noShow = makeMemberHoursShift('2027-08-05 08:00', '2027-08-05 12:00', ShiftStatus::Confirmed, AttendanceStatus::NoShow);
    $ended = makeMemberHoursShift('2027-08-05 08:00', '2027-08-05 12:00', ShiftStatus::Confirmed);
    $future = makeMemberHoursShift('2027-08-05 16:00', '2027-08-05 20:00', ShiftStatus::Confirmed);

    expect($calculator->workedMinutesForShift($shortened, $now))->toBe(195)
        ->and($calculator->workedMinutesForShift($noShow, $now))->toBe(0)
        ->and($calculator->workedMinutesForShift($ended, $now))->toBe(240)
        ->and($calculator->workedMinutesForShift($future, $now))->toBeNull();
});

it('counts goal progress once across confirmed, pending, and adjusted intervals', function () {
    $now = CarbonImmutable::parse('2027-08-05 15:00', 'Europe/Copenhagen');
    $shifts = [
        makeMemberHoursShift('2027-08-06 13:00', '2027-08-06 15:00', ShiftStatus::Pending),
        makeMemberHoursShift('2027-08-06 10:00', '2027-08-06 14:00'),
        makeMemberHoursShift(
            '2027-08-06 14:00',
            '2027-08-06 18:00',
            ShiftStatus::Confirmed,
            AttendanceStatus::Adjusted,
            '2027-08-06 14:30',
            '2027-08-06 16:45',
        ),
        makeMemberHoursShift('2027-08-06 18:00', '2027-08-06 19:00', ShiftStatus::Confirmed, AttendanceStatus::NoShow),
        makeMemberHoursShift('2027-08-06 19:00', '2027-08-06 20:00', ShiftStatus::Rejected),
        makeMemberHoursShift('2027-08-06 20:00', '2027-08-06 21:00', ShiftStatus::Cancelled),
        makeMemberHoursShift('2027-08-05 10:00', '2027-08-05 11:00'),
    ];

    $summary = (new MemberHours)->summarize($shifts, $now);

    // The goal includes 1h on Aug 5 and the union [10:00, 16:45) on Aug 6.
    expect($summary->goalProgressMinutes)->toBe(465)
        ->and($summary->confirmedCount)->toBe(4)
        ->and($summary->pendingCount)->toBe(1)
        ->and($summary->plannedMinutes)->toBe(600)
        ->and($summary->workedMinutes)->toBe(195);
});

it('counts the overlap between confirmed and pending overnight shifts only once toward the hour goal', function () {
    $now = CarbonImmutable::parse('2027-08-05 12:00', 'Europe/Copenhagen');
    $shifts = [
        makeMemberHoursShift('2027-08-05 21:30', '2027-08-06 01:30'),
        makeMemberHoursShift('2027-08-05 23:30', '2027-08-06 03:00', ShiftStatus::Pending),
    ];

    $summary = (new MemberHours)->summarize($shifts, $now);

    expect($summary->goalProgressMinutes)->toBe(330);
});
