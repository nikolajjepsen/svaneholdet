<?php

namespace App\Scheduling;

use App\Models\Slot;

final readonly class SlotActionResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?Slot $slot = null,
        public array $warnings = [],
        public int $updatedCount = 0,
    ) {}

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
