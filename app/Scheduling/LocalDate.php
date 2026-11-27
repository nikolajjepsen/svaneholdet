<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

final class LocalDate
{
    public static function parse(?string $date, string $timezone): ?CarbonImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        } catch (InvalidFormatException) {
            return null;
        }

        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $parsed;
    }
}
