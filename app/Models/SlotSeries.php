<?php

namespace App\Models;

use Database\Factories\SlotSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $local_start_time
 * @property string $local_end_time
 * @property int $capacity
 */
#[Fillable(['season_id', 'local_start_time', 'local_end_time', 'capacity'])]
class SlotSeries extends Model
{
    /** @use HasFactory<SlotSeriesFactory> */
    use HasFactory;

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return HasMany<Slot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }
}
