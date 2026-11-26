<?php

use App\Mail\LoginLinkMail;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('saves the selected language and uses it on the next request', function () {
    $user = User::factory()->has(Membership::factory()->count(2))->create(['locale' => 'en']);

    $this->actingAs($user)
        ->get('/settings/language')
        ->assertOk()
        ->assertSee('Language settings')
        ->assertSee('lang="en"', false);

    Livewire::test('pages::settings.language')
        ->set('locale', 'da')
        ->call('updateLanguage')
        ->assertHasNoErrors()
        ->assertRedirect('/settings/language');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'locale' => 'da']);
    $this->actingAs($user->refresh())
        ->get('/settings/language')
        ->assertOk()
        ->assertSee('Sprogindstillinger')
        ->assertSee('lang="da"', false);
});

it('sends a Danish login link to a member with the default language', function () {
    Mail::fake();
    $user = User::factory()->has(Membership::factory()->count(2))->create();

    $this->post('/login', ['email' => $user->email])->assertRedirect('/login');
    $this->get('/login')->assertSee('Check your email');

    Mail::sent(LoginLinkMail::class)->sole()
        ->assertHasSubject('Dit loginlink')
        ->assertSeeInHtml('Åbn loginlink')
        ->assertSeeInText('Loginlinket udløber efter 15 minutter og kan kun bruges én gang.');
});

it('rejects unsupported languages without changing the member’s preferred language', function () {
    $user = User::factory()->has(Membership::factory()->count(2))->create(['locale' => 'en']);
    $this->actingAs($user);

    Livewire::test('pages::settings.language')
        ->set('locale', 'sv')
        ->call('updateLanguage')
        ->assertHasErrors(['locale' => 'in'])
        ->assertSee('Choose Danish or English.');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'locale' => 'en']);
});

it('shows logged-out pages in Danish when using the app default', function () {
    config(['app.locale' => 'da']);
    $user = User::factory()->has(Membership::factory()->count(2))->create(['locale' => 'en']);

    $this->actingAs($user)->get('/dashboard')->assertSee('Dashboard');
    $this->post('/logout')->assertRedirect('/');

    $this->get('/')->assertOk()->assertSee('Log ind')->assertSee('lang="da"', false);
    $this->get('/login')->assertOk()->assertSee('E-mailadresse')->assertSee('Send loginlink');
});
