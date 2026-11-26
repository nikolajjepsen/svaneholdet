<?php

use App\Models\Membership;
use App\Models\User;

it('lets a member switch between Danish and English from settings', function () {
    $user = User::factory()->has(Membership::factory()->count(2))->create();
    $this->actingAs($user);

    visit('/settings/profile')
        ->click('Sprog')
        ->assertPathIs('/settings/language')
        ->assertTitleContains('Sprogindstillinger')
        ->select('locale', 'en')
        ->click('@save-language')
        ->assertSee('Choose the language for the app and emails')
        ->assertAttribute('html[lang]', 'lang', 'en')
        ->assertTitleContains('Language settings')
        ->assertValue('locale', 'en')
        ->select('locale', 'da')
        ->click('@save-language')
        ->assertSee('Vælg sprog for appen og e-mails')
        ->assertAttribute('html[lang]', 'lang', 'da')
        ->assertValue('locale', 'da')
        ->assertNoSmoke();
});
