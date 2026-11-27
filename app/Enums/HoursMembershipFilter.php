<?php

namespace App\Enums;

enum HoursMembershipFilter: string
{
    case All = 'all';
    case Active = 'active';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::All => __('All'),
            self::Active => __('Active'),
            self::Removed => __('Removed'),
        };
    }
}
