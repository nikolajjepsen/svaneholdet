<x-mail::message>
# {{ __('Invitation to :team', ['team' => $teamName]) }}

{{ __('You are invited to join the team as a volunteer. This invitation expires in 14 days and can be used once.') }}

<x-mail::button :url="$url">
{{ __('Open invitation') }}
</x-mail::button>
</x-mail::message>
