<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $name = '';

    public $profilePicture = null;

    public function mount(): void
    {
        $this->name = Auth::user()->name;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'profilePicture' => ['bail', 'nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ], [
            'profilePicture.file' => __('Choose a JPG, PNG or WebP image.'),
            'profilePicture.mimes' => __('Choose a JPG, PNG or WebP image.'),
            'profilePicture.max' => __('The profile picture must be 5 MB or smaller.'),
            'profilePicture.uploaded' => __('The profile picture must be 5 MB or smaller.'),
        ]);

        if (isset($validated['profilePicture'])) {
            $this->saveProfileWithPicture($user, $validated['name'], $validated['profilePicture']);
        } else {
            $user->update(['name' => $validated['name']]);
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    protected function saveProfileWithPicture(User $user, string $name, UploadedFile $picture): void
    {
        $disk = Storage::disk('public');

        try {
            $path = Image::fromUpload($picture)->cover(256, 256)->toWebp()->store('profile-pictures', 'public');
        } catch (ImageException) {
            throw ValidationException::withMessages(['profilePicture' => __('This image could not be opened.')]);
        }

        if ($path === false) {
            throw ValidationException::withMessages(['profilePicture' => __('The profile picture could not be saved. Please try again.')]);
        }

        try {
            $previousPath = DB::transaction(function () use ($user, $name, $path): ?string {
                $previousPath = User::query()->lockForUpdate()->findOrFail($user->id, ['profile_picture_path'])->profile_picture_path;
                $user->name = $name;
                $user->profile_picture_path = $path;
                $user->save();

                return $previousPath;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        if ($previousPath !== null) {
            $disk->delete($previousPath);
        }

        $this->redirectRoute('profile.edit', navigate: true);
    }

    public function render(): View
    {
        return $this->view()->title(__('Profile settings'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and profile picture')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <flux:input :value="Auth::user()->email" :label="__('Email')" type="email" readonly />

            <flux:input
                wire:model="profilePicture"
                :label="__('Profile picture')"
                :description="__('JPG, PNG or WebP, up to 5 MB.')"
                type="file"
                accept="image/jpeg,image/png,image/webp"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" wire:loading.attr="disabled" wire:target="profilePicture" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
