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
}
