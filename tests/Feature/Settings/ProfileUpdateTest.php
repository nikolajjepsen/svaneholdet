<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_displays_the_users_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee($user->email)
            ->assertSeeHtml('readonly');
    }

    public function test_user_can_update_their_name(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('name', 'Updated Name')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->get(route('profile.edit'))->assertSee('Updated Name');
    }

    public function test_name_is_required_when_updating_the_profile(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.profile')
            ->set('name', '')
            ->call('updateProfileInformation')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_appearance_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('appearance.edit'))
            ->assertOk()
            ->assertSee('Update the appearance settings for your account');
    }
}
