<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;

final readonly class TeamSettings implements Castable, JsonSerializable
{
    public const int DEFAULT_CANCELLATION_DEADLINE_HOURS = 48;

    public const bool DEFAULT_SHIFTS_REQUIRE_CONFIRMATION = false;

    public const bool DEFAULT_SHOW_CONFIRMED_VOLUNTEER_NAMES = true;

    public function __construct(
        public int $cancellationDeadlineHours = self::DEFAULT_CANCELLATION_DEADLINE_HOURS,
        public bool $shiftsRequireConfirmation = self::DEFAULT_SHIFTS_REQUIRE_CONFIRMATION,
        public bool $showConfirmedVolunteerNames = self::DEFAULT_SHOW_CONFIRMED_VOLUNTEER_NAMES,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $cancellationDeadlineHours = array_key_exists('cancellation_deadline_hours', $data)
            && is_int($data['cancellation_deadline_hours'])
            ? $data['cancellation_deadline_hours']
            : self::DEFAULT_CANCELLATION_DEADLINE_HOURS;

        $shiftsRequireConfirmation = array_key_exists('shifts_require_confirmation', $data)
            && is_bool($data['shifts_require_confirmation'])
            ? $data['shifts_require_confirmation']
            : self::DEFAULT_SHIFTS_REQUIRE_CONFIRMATION;

        $showConfirmedVolunteerNames = array_key_exists('show_confirmed_volunteer_names', $data)
            && is_bool($data['show_confirmed_volunteer_names'])
            ? $data['show_confirmed_volunteer_names']
            : self::DEFAULT_SHOW_CONFIRMED_VOLUNTEER_NAMES;

        return new self(
            cancellationDeadlineHours: $cancellationDeadlineHours,
            shiftsRequireConfirmation: $shiftsRequireConfirmation,
            showConfirmedVolunteerNames: $showConfirmedVolunteerNames,
        );
    }

    /**
     * @return array{
     *     cancellation_deadline_hours: int,
     *     shifts_require_confirmation: bool,
     *     show_confirmed_volunteer_names: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'cancellation_deadline_hours' => $this->cancellationDeadlineHours,
            'shifts_require_confirmation' => $this->shiftsRequireConfirmation,
            'show_confirmed_volunteer_names' => $this->showConfirmedVolunteerNames,
        ];
    }

    /**
     * @return array{
     *     cancellation_deadline_hours: int,
     *     shifts_require_confirmation: bool,
     *     show_confirmed_volunteer_names: bool,
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<int, string>  $arguments
     * @return CastsAttributes<self, self|array<string, mixed>|null>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(Model $model, string $key, mixed $value, array $attributes): TeamSettings
            {
                if ($value instanceof TeamSettings) {
                    return $value;
                }

                if ($value === null || $value === '') {
                    return new TeamSettings;
                }

                if (is_string($value)) {
                    $decoded = json_decode($value, true);

                    return is_array($decoded) ? TeamSettings::fromArray($decoded) : new TeamSettings;
                }

                if (is_array($value)) {
                    return TeamSettings::fromArray($value);
                }

                return new TeamSettings;
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): string
            {
                if ($value === null) {
                    return json_encode((new TeamSettings)->toArray(), JSON_THROW_ON_ERROR);
                }

                if ($value instanceof TeamSettings) {
                    return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
                }

                if (is_array($value)) {
                    return json_encode(TeamSettings::fromArray($value)->toArray(), JSON_THROW_ON_ERROR);
                }

                throw new InvalidArgumentException('Team settings must be a TeamSettings instance or an array.');
            }
        };
    }
}
