<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\ShiftStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $capacity
 */
#[Fillable(['season_id', 'slot_series_id', 'starts_at', 'ends_at', 'capacity'])]
class Slot extends Model
{
    /** @use HasFactory<SlotFactory> */
    use HasFactory;

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<SlotSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(SlotSeries::class, 'slot_series_id');
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** @return HasMany<Shift, $this> */
    public function confirmedShifts(): HasMany
    {
        return $this->shifts()->where('status', ShiftStatus::Confirmed);
    }

    public function capacityRole(): MembershipRole
    {
        return MembershipRole::Volunteer;
    }

    public function confirmedCapacityCount(): int
    {
        return $this->confirmedShifts()
            ->whereRelation('membership', 'role', $this->capacityRole())
            ->count();
    }

    /**
     * @param  Collection<int, Shift>  $confirmedShifts
     * @return Collection<int, Shift>
     */
    public function capacityCountingShifts(Collection $confirmedShifts): Collection
    {
        return $confirmedShifts->filter(fn (Shift $shift): bool => $shift->membership->role === $this->capacityRole());
    }

    /** @return HasMany<Shift, $this> */
    public function pendingShifts(): HasMany
    {
        return $this->shifts()->where('status', ShiftStatus::Pending);
    }

    public function plannedHours(): float
    {
        return round($this->starts_at->diffInMinutes($this->ends_at) / 60, 2);
    }

    public function cancellationDeadline(Team $team): CarbonImmutable
    {
        return $this->starts_at->subHours($team->settings->cancellationDeadlineHours);
    }

    public function isBeforeCancellationDeadline(Team $team, ?CarbonImmutable $at = null): bool
    {
        return ($at ?? now())->lessThanOrEqualTo($this->cancellationDeadline($team));
    }

    public function hasStarted(?CarbonImmutable $at = null): bool
    {
        return $this->starts_at->lessThanOrEqualTo($at ?? now());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
        ];
    }
}
