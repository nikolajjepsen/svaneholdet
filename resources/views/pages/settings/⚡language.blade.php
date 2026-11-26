<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

new class extends Component
{
    public string $locale = '';

    public function mount(): void
    {
        $this->locale = Auth::user()->locale;
    }

    public function updateLanguage(): void
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $validated = $this->validate([
            'locale' => ['required', 'in:da,en'],
        ], [
            'locale.required' => __('Choose Danish or English.'),
            'locale.in' => __('Choose Danish or English.'),
        ]);

        $user->update($validated);

        $this->redirectRoute('language.edit');
    }

    public function render(): View
    {
        return $this->view()->title(__('Language settings'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Language settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Language')" :subheading="__('Choose the language for the app and emails')">
        <form wire:submit="updateLanguage" class="my-6 w-full space-y-6">
            <flux:select wire:model="locale" name="locale" :label="__('Language')">
                <flux:select.option value="da">{{ __('Danish') }}</flux:select.option>
                <flux:select.option value="en">{{ __('English') }}</flux:select.option>
            </flux:select>

            <flux:button variant="primary" type="submit" data-test="save-language">{{ __('Save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
