<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Planned = 'planned';
    case Adjusted = 'adjusted';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planned'),
            self::Adjusted => __('Adjusted'),
            self::NoShow => __('No-show'),
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Planned => 'green',
            self::Adjusted => 'blue',
            self::NoShow => 'red',
        };
    }
}
