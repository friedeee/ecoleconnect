<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_login_page_is_displayed(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Connexion');
    }

    public function test_parents_switch_the_login_form_to_their_phone_number(): void
    {
        Livewire::test('pages::auth.login')
            ->assertSee('Adresse e-mail')
            ->set('email', 'quelquechose')
            ->set('audience', 'parent')
            ->assertSee('Numéro de téléphone')
            ->assertSee('Demandez vos identifiants')
            ->assertSet('email', '')
            ->set('audience', 'nimporte')
            ->assertSet('audience', 'staff');
    }

    public function test_password_fields_can_be_shown_while_typing(): void
    {
        $this->get(route('login'))
            ->assertSee('aria-label="Afficher le mot de passe"', escape: false)
            ->assertSee('x-bind:type="visible ? \'text\' : \'password\'"', escape: false);
    }

    public function test_staff_can_log_in_and_reach_the_dashboard(): void
    {
        $user = $this->schoolAdmin();

        Livewire::test('pages::auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertOk()->assertSee('Bonjour, '.$user->name);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $user = $this->schoolAdmin();

        Livewire::test('pages::auth.login')
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email' => 'Ces identifiants ne correspondent à aucun compte actif.']);

        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        Livewire::test('pages::auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $user = $this->schoolAdmin();
        $component = Livewire::test('pages::auth.login')->set('email', $user->email)->set('password', 'wrong-password');

        foreach (range(1, 5) as $attempt) {
            $component->call('login');
        }

        $component->set('password', 'password')->call('login');

        $this->assertGuest();
        $this->assertStringStartsWith('Trop de tentatives', $component->errors()->first('email'));
    }

    public function test_a_user_deactivated_during_a_session_is_logged_out(): void
    {
        $user = $this->schoolAdmin();
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_users_of_a_suspended_school_are_logged_out(): void
    {
        $user = $this->schoolAdmin(School::factory()->create(['is_active' => false]));

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_parent_accounts_are_sent_to_the_parent_portal(): void
    {
        $this->actingAs(User::factory()->parent()->create())->get(route('dashboard'))->assertRedirect(route('parent.home'));
        $this->get(route('students.index'))->assertRedirect(route('parent.home'));
    }

    public function test_users_can_log_out(): void
    {
        $this->actingAs($this->schoolAdmin())->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
