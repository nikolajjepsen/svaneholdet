<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ShiftStatus $status
 * @property CarbonImmutable|null $confirmed_at
 * @property AttendanceStatus $attendance_status
 * @property CarbonImmutable|null $actual_starts_at
 * @property CarbonImmutable|null $actual_ends_at
 * @property int|null $attendance_recorded_by
 * @property CarbonImmutable|null $attendance_recorded_at
 */
#[Fillable([
    'slot_id',
    'membership_id',
    'status',
    'confirmed_at',
    'attendance_status',
    'actual_starts_at',
    'actual_ends_at',
    'attendance_recorded_by',
    'attendance_recorded_at',
])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'attendance_status' => AttendanceStatus::Planned->value,
    ];

    /** @return BelongsTo<Slot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attendanceRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_recorded_by');
    }

    public function hasAttendanceOverride(): bool
    {
        return $this->attendance_status !== AttendanceStatus::Planned;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'confirmed_at' => 'immutable_datetime',
            'attendance_status' => AttendanceStatus::class,
            'actual_starts_at' => 'immutable_datetime',
            'actual_ends_at' => 'immutable_datetime',
            'attendance_recorded_at' => 'immutable_datetime',
        ];
    }
}
