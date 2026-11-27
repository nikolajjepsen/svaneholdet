<x-mail::message>
# {{ __('Your shift on :team was cancelled', ['team' => $teamName]) }}

{{ __('An admin cancelled your shift on :date (:time).', ['date' => $dateLabel, 'time' => $timeRange]) }}

<x-mail::button :url="$url">
{{ __('View my shifts') }}
</x-mail::button>
</x-mail::message>
