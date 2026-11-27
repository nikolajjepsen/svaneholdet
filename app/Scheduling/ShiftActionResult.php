<?php

namespace App\Scheduling;

use App\Models\Shift;

final readonly class ShiftActionResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?Shift $shift = null,
        public array $warnings = [],
    ) {}

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
