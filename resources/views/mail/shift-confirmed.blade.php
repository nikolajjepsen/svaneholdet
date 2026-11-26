<x-mail::message>
# {{ __('Your shift on :team is confirmed', ['team' => $teamName]) }}

{{ __('Your shift on :date (:time) has been confirmed.', ['date' => $dateLabel, 'time' => $timeRange]) }}

<x-mail::button :url="$url">
{{ __('View my shifts') }}
</x-mail::button>
</x-mail::message>
