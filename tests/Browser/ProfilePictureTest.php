<?php

use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

it('lets a user save a name and picture and shows the picture in both user menus', function () {
    config(['filesystems.disks.public.url' => '/storage']);
    Route::getRoutes()->getByName('livewire.upload-file')->middleware(NormalizeBrowserUploadFiles::class);
    Storage::fake('tmp-for-tests');
    $user = User::factory()->has(Membership::factory()->count(2))->create([
        'name' => 'Original Name',
    ]);
    $picture = UploadedFile::fake()->image('portrait.png', 520, 320);
    $this->actingAs($user);

    try {
        visit('/settings/profile')
            ->resize(1280, 800)
            ->assertSeeIn('@sidebar-menu-button', 'ON')
            ->assertValue('input[type=email]', $user->email)
            ->assertPresent('input[type=email][readonly]')
            ->fill('name', 'Updated Name')
            ->attach('profilePicture', $picture->getPathname())
            ->click('@update-profile-button')
            ->assertSee('Profilen er opdateret.')
            ->assertSeeIn('@sidebar-menu-button', 'Updated Name')
            ->click('@sidebar-menu-button')
            ->assertVisible('[data-flux-menu] [data-flux-avatar] img:visible')
            ->navigate('/settings/profile')
            ->resize(390, 844)
            ->click('@mobile-user-menu-button')
            ->assertVisible('[data-flux-menu] [data-flux-avatar] img:visible')
            ->assertScript(<<<'JS'
                function () {
                    const avatars = document.querySelectorAll('[data-flux-dropdown] [data-flux-avatar] img');
                    return Promise.all(Array.from(avatars, (image) => image.decode().then(() => image.naturalWidth)));
                }
                JS, [256, 256, 256, 256])
            ->assertNoSmoke();
    } finally {
        if ($path = $user->refresh()->profile_picture_path) {
            Storage::disk('public')->delete($path);
        }
    }
});

class NormalizeBrowserUploadFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        // Pest's browser server keeps the multipart field name literal instead of parsing it as PHP does.
        if ($request->files->has('files[]')) {
            $request->files->set('files', [$request->files->get('files[]')]);
        }

        return $next($request);
    }
}
