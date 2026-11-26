<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public function plannedHours(): float
    {
        return round($this->starts_at->diffInMinutes($this->ends_at) / 60, 2);
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
