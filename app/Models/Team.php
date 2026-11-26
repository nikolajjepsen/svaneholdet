<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone', 'current_season_id'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /** @return HasMany<Season, $this> */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    /** @return BelongsTo<Season, $this> */
    public function currentSeason(): BelongsTo
    {
        return $this->belongsTo(Season::class, 'current_season_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Membership, $this> */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', MembershipStatus::Active);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
