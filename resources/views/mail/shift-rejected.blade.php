<x-mail::message>
# {{ __('Your shift request on :team was rejected', ['team' => $teamName]) }}

{{ __('Your shift request on :date (:time) was rejected.', ['date' => $dateLabel, 'time' => $timeRange]) }}

<x-mail::button :url="$url">
{{ __('View schedule') }}
</x-mail::button>
</x-mail::message>
