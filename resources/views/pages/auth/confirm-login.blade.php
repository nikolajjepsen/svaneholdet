<x-layouts::auth :title="__('Log in')">
    <flux:heading size="xl" level="1">{{ __('Log in') }}</flux:heading>

    <form method="POST" action="{{ route('login.consume', ['token' => $token]) }}">
        @csrf
        <flux:button type="submit" variant="primary" class="w-full" data-test="confirm-login">
            {{ __('Log in') }}
        </flux:button>
    </form>
</x-layouts::auth>
