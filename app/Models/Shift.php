<?php

namespace App\Models;

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
 */
#[Fillable(['slot_id', 'membership_id', 'status', 'confirmed_at'])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
