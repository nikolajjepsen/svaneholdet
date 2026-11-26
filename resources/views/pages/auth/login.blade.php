<x-layouts::auth :title="__('Log in')">
    <flux:heading size="xl" level="1">{{ __('Log in') }}</flux:heading>

    @if (($invalidLink ?? false) === true)
        <flux:callout :text="__('This login link is no longer available.')" />
    @endif

    @if (session('status') && ! ($invalidLink ?? false))
        <flux:callout role="status" :text="session('status')" />
    @else
        <form method="POST" action="{{ route('login.request') }}" class="flex flex-col gap-4">
            @csrf

            <flux:input name="email" type="email" :label="__('Email address')" autocomplete="email" :value="$email ?? null" required />

            <flux:button type="submit" variant="primary" data-test="send-login-link">
                {{ __('Send login link') }}
            </flux:button>
        </form>
    @endif
</x-layouts::auth>
