@use(App\Enums\MembershipRole)
@use(App\Models\Shift)

@props(['shifts'])

@php
    $admins = $shifts->whereStrict('membership.role', MembershipRole::Admin);
@endphp

@if ($admins->isNotEmpty())
    <div {{ $attributes }}>
        <flux:heading size="sm" level="3">{{ __('Admins') }}</flux:heading>
        <flux:text class="text-sm">
            {{ $admins->map(fn (Shift $shift): string => $shift->membership->user->firstName())->join(', ') }}
        </flux:text>
    </div>
@endif
