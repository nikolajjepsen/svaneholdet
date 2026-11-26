<x-mail::message>
# {{ __('Log in to :app', ['app' => config('app.name')]) }}

{{ __('This login link expires in 15 minutes and can be used once.') }}

<x-mail::button :url="$url">
{{ __('Open login link') }}
</x-mail::button>

{{ __('If you did not request a login link, you can ignore this email.') }}
</x-mail::message>
