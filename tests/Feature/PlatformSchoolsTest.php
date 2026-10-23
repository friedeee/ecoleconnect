<?php

namespace Tests\Feature;

use App\Enums\Cycle;
use App\Enums\Role;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformSchoolsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->superAdmin()->create();
    }

    public function test_the_operator_lands_on_the_platform_instead_of_a_school_dashboard(): void
    {
        $this->actingAs($this->operator)
            ->get(route('dashboard'))
            ->assertRedirect(route('platform.schools.index'));
    }

    public function test_the_operator_sees_every_school(): void
    {
        School::factory()->create(['name' => 'Collège Le Phare']);
        School::factory()->teaching(Cycle::Primary)->create(['name' => 'École La Source']);

        $this->actingAs($this->operator)
            ->get(route('platform.schools.index'))
            ->assertOk()
            ->assertSee('Collège Le Phare')
            ->assertSee('École La Source');

        $this->get(route('platform.schools.create'))
            ->assertOk()
            ->assertSee('Cycles proposés')
            ->assertSee('Maternelle 1 → Maternelle 2');
    }

    public function test_school_staff_cannot_enter_the_platform(): void
    {
        $this->actingAs($this->schoolAdmin())
            ->get(route('platform.schools.index'))
            ->assertForbidden();

        $this->get(route('platform.schools.create'))->assertForbidden();
    }

    public function test_the_operator_opens_a_school_ready_to_use(): void
    {
        $this->actingAs($this->operator);

        $component = Livewire::test('pages::platform.schools.create')
            ->set('name', 'École Primaire Les Palmiers')
            ->set('city', 'Abomey')
            ->set('cycles', ['primary', 'nursery'])
            ->set('admin_name', 'Mme Rachelle KPADONOU')
            ->set('admin_email', 'Direction@Palmiers.bj')
            ->call('create')
            ->assertHasNoErrors();

        $school = School::sole();
        $this->assertSame([Cycle::Nursery, Cycle::Primary], $school->cycles->all());
        $this->assertTrue($school->currentAcademicYear()->exists());

        $subjectCycles = Subject::where('school_id', $school->id)->pluck('cycle')->unique()->sortBy(fn (Cycle $cycle) => $cycle->value)->values()->all();
        $this->assertSame([Cycle::Nursery, Cycle::Primary], $subjectCycles);

        $admin = User::where('school_id', $school->id)->sole();
        $this->assertSame('direction@palmiers.bj', $admin->email);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue(Hash::check($component->get('credentials')['password'], $admin->password));
        $component->assertSee('direction@palmiers.bj');
    }

    public function test_a_school_needs_at_least_one_cycle_and_a_new_admin_email(): void
    {
        $existing = User::factory()->create();

        Livewire::actingAs($this->operator)
            ->test('pages::platform.schools.create')
            ->set('name', 'Doublon')
            ->set('admin_name', 'X')
            ->set('admin_email', $existing->email)
            ->call('create')
            ->assertHasErrors(['cycles' => 'required', 'admin_email' => 'unique']);

        $this->assertSame(1, School::count());
    }

    public function test_the_operator_suspends_a_school_and_its_staff_is_logged_out(): void
    {
        $admin = $this->schoolAdmin();

        Livewire::actingAs($this->operator)
            ->test('pages::platform.schools.index')
            ->call('toggleActive', $admin->school_id);

        $this->assertFalse($admin->school->fresh()->is_active);

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_school_admin_cannot_call_platform_actions(): void
    {
        $admin = $this->schoolAdmin();
        $otherSchool = School::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::platform.schools.index')
            ->call('toggleActive', $otherSchool->id)
            ->assertForbidden();

        $this->assertTrue($otherSchool->fresh()->is_active);
    }

    public function test_the_operator_account_is_created_from_the_command_line(): void
    {
        $this->artisan('platform:create-admin', ['--name' => 'Jean HOUNSOU', '--email' => 'Ops@EcoleConnect.bj'])
            ->expectsQuestion('Mot de passe (12 caractères minimum)', 'un-mot-de-passe-solide')
            ->assertSuccessful();

        $operator = User::where('email', 'ops@ecoleconnect.bj')->sole();
        $this->assertSame(Role::SuperAdmin, $operator->role);
        $this->assertNull($operator->school_id);
    }
}
