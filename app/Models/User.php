<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $locale
 * @property string|null $profile_picture_path
 * @property int|null $last_chosen_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'locale'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['locale' => 'da', 'profile_picture_path' => null, 'last_chosen_team_id' => null];

    protected $rememberTokenName = null;

    public function preferredLocale(): string
    {
        return $this->locale;
    }

    public function getAuthPassword(): string
    {
        return '';
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

    /** @return BelongsTo<Team, $this> */
    public function lastChosenTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'last_chosen_team_id');
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $email): string => Str::lower($email));
    }

    public function profilePictureUrl(): ?string
    {
        return $this->profile_picture_path === null
            ? null
            : Storage::disk('public')->url($this->profile_picture_path);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
