<x-mail::message>
# {{ __('Your shift times on :team changed', ['team' => $teamName]) }}

{{ __('An admin changed the times of your shift on :date from :oldTime to :newTime.', ['date' => $dateLabel, 'oldTime' => $oldTimeRange, 'newTime' => $newTimeRange]) }}

<x-mail::button :url="$url">
{{ __('View my shifts') }}
</x-mail::button>
</x-mail::message>
