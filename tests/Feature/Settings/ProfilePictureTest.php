<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('stores a square profile picture on the public disk', function (string $extension) {
    $disk = Storage::fake('public');
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('profilePicture', UploadedFile::fake()->image("portrait.{$extension}", 520, 320)->size(5120))
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $path = $user->refresh()->profile_picture_path;
    $disk->assertExists($path);

    expect(getimagesize($disk->path($path)))->toMatchArray([0 => 256, 1 => 256]);
})->with(['jpg', 'png', 'webp']);

it('removes the previous picture after saving a replacement', function () {
    $disk = Storage::fake('public');
    $previousPath = 'profile-pictures/existing.webp';
    $disk->put($previousPath, 'existing picture');
    $user = User::factory()->create(['profile_picture_path' => $previousPath]);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('profilePicture', UploadedFile::fake()->image('replacement.jpg'))
        ->call('updateProfileInformation')
        ->assertRedirectToRoute('profile.edit');

    $disk->assertExists($user->refresh()->profile_picture_path);
    $disk->assertMissing($previousPath);
});

it('keeps the existing profile and shows an error when a picture is rejected', function (UploadedFile $picture, string $message) {
    $disk = Storage::fake('public');
    $disk->put('profile-pictures/existing.webp', 'existing picture');
    $user = User::factory()->create([
        'name' => 'Original Name',
        'locale' => 'da',
        'profile_picture_path' => 'profile-pictures/existing.webp',
    ]);
    $this->actingAs($user);
    app()->setLocale('da');

    Livewire::test('pages::settings.profile')
        ->set('name', 'Unsaved Name')
        ->set('profilePicture', $picture)
        ->call('updateProfileInformation')
        ->assertHasErrors('profilePicture')
        ->assertSee($message);

    expect($user->refresh())
        ->name->toBe('Original Name')
        ->profile_picture_path->toBe('profile-pictures/existing.webp');
    expect($disk->allFiles())->toBe(['profile-pictures/existing.webp']);
})->with([
    'unsupported image' => [
        fn () => UploadedFile::fake()->image('portrait.gif'),
        'Vælg et JPG-, PNG- eller WebP-billede.',
    ],
    'oversized image' => [
        fn () => UploadedFile::fake()->image('portrait.jpg')->size(5121),
        'Profilbilledet må højst fylde 5 MB.',
    ],
    'unreadable image' => [
        fn () => UploadedFile::fake()->createWithContent('portrait.png', substr(UploadedFile::fake()->image('portrait.png')->getContent(), 0, 29)),
        'Billedet kunne ikke åbnes.',
    ],
]);
