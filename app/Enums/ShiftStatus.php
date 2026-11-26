<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Confirmed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Confirmed => __('Confirmed'),
            self::Rejected => __('Rejected'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
