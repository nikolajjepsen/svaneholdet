<x-mail::message>
# {{ __('Your shift on :team was cancelled', ['team' => $teamName]) }}

{{ __('An admin deleted the slot on :date (:time), so your shift has been cancelled.', ['date' => $dateLabel, 'time' => $timeRange]) }}

<x-mail::button :url="$url">
{{ __('View my shifts') }}
</x-mail::button>
</x-mail::message>
