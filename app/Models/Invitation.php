<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $expires_at
 */
#[Fillable(['team_id', 'invited_by', 'name', 'email', 'token_hash', 'expires_at', 'redeemed_at', 'withdrawn_at'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->redeemed_at !== null => InvitationStatus::Redeemed,
            $this->withdrawn_at !== null => InvitationStatus::Withdrawn,
            $this->expires_at->lte(now()) => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @param Builder<Invitation> $query */
    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereNull('redeemed_at')->whereNull('withdrawn_at');
    }

    /** @param Builder<Invitation> $query */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->unresolved()->where('expires_at', '>', now());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }
}
