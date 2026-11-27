<?php

namespace App\Models;

use Database\Factories\SlotSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $local_start_time
 * @property string $local_end_time
 * @property int $capacity
 * @property bool $is_admin_only
 */
#[Fillable(['season_id', 'local_start_time', 'local_end_time', 'capacity', 'is_admin_only'])]
class SlotSeries extends Model
{
    protected $attributes = ['is_admin_only' => false];

    /** @use HasFactory<SlotSeriesFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $series): void {
            if ($series->isDirty('is_admin_only')) {
                throw new LogicException('The Admin-only flag cannot change after series creation.');
            }
        });
    }

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
            'is_admin_only' => 'boolean',
        ];
    }
}
