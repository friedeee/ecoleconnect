<?php

namespace Tests\Feature;

use App\Enums\ClassLevel;
use App\Enums\Role;
use App\Enums\Section;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_leads_to_the_profile(): void
    {
        $this->actingAs($this->schoolAdmin())
            ->get(route('dashboard'))
            ->assertSee(route('profile'), escape: false)
            ->assertSee('Mon profil');
    }

    public function test_staff_see_their_information_and_what_they_can_do(): void
    {
        $admin = $this->schoolAdmin();
        $director = User::factory()->admin()->for($admin->school)->inSection(Section::Basic)->create(['name' => 'M. Firmin DOSSA', 'email' => 'primaire@ecole.bj', 'phone' => '+229 01 90 00 00 00']);

        $this->actingAs($director)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('M. Firmin DOSSA')
            ->assertSee('primaire@ecole.bj')
            ->assertSee('+229 01 90 00 00 00')
            ->assertSee('Maternelle et primaire')
            ->assertSee($admin->school->name)
            ->assertSee('Gérer les classes, les matières et le personnel')
            ->assertDontSee('Gérer les paramètres de l');
    }

    public function test_staff_update_their_name_and_phone_but_not_their_role(): void
    {
        $teacher = User::factory()->for($this->schoolAdmin()->school)->create(['role' => Role::Teacher, 'name' => 'Ancien nom']);

        Livewire::actingAs($teacher)
            ->test('pages::profile')
            ->call('edit')
            ->set('name', 'Mme Aïcha TOSSOU')
            ->set('phone', 'pas un numéro')
            ->call('save')
            ->assertHasErrors(['phone'])
            ->set('phone', '+229 01 66 77 88 99')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Vos informations ont été enregistrées.');

        $teacher->refresh();
        $this->assertSame('Mme Aïcha TOSSOU', $teacher->name);
        $this->assertSame('+229 01 66 77 88 99', $teacher->phone);
        $this->assertSame(Role::Teacher, $teacher->role);
    }

    public function test_a_teacher_sees_the_classes_and_subjects_they_teach(): void
    {
        $school = $this->schoolAdmin()->school;
        $teacher = User::factory()->for($school)->create(['role' => Role::Teacher]);
        $class = SchoolClass::factory()->for($school)->for($school->currentAcademicYear)->create(['name' => '5e B', 'level' => ClassLevel::Fifth, 'head_teacher_id' => $teacher->id]);
        $maths = Subject::factory()->for($school)->create(['name' => 'Mathématiques']);
        $french = Subject::factory()->for($school)->create(['name' => 'Français']);
        $class->subjects()->attach([$maths->id => ['coefficient' => 3, 'teacher_id' => $teacher->id], $french->id => ['coefficient' => 3, 'teacher_id' => null]]);

        $this->actingAs($teacher)
            ->get(route('profile'))
            ->assertSee('Mes classes et matières')
            ->assertSee('5e B')
            ->assertSee('Professeur principal')
            ->assertSee('Mathématiques')
            ->assertDontSee('Français');
    }

    public function test_a_parent_sees_their_profile_in_the_parent_portal_without_editing_it(): void
    {
        $school = $this->schoolAdmin()->school;
        $class = SchoolClass::factory()->for($school)->for($school->currentAcademicYear)->create(['name' => 'CP', 'level' => ClassLevel::Cp]);
        $child = Enrollment::factory()->create(['school_class_id' => $class->id])->student;
        $guardian = Guardian::factory()->for($school)->create(['first_name' => 'Rachida', 'phone' => '+229 01 51 52 53 54']);
        $child->guardians()->attach($guardian->id, ['relationship' => 'mother', 'is_primary_contact' => true]);
        $guardian->openPortalAccount();
        $parent = $guardian->fresh()->user;
        $parent->forceFill(['must_change_password' => false])->save();

        $this->actingAs($parent)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Espace parents')
            ->assertSee('Téléphone de connexion')
            ->assertSee('0151525354')
            ->assertSee('Mes enfants')
            ->assertSee($child->first_name)
            ->assertSee('adressez-vous à l');

        Livewire::actingAs($parent)->test('pages::profile')->call('edit')->assertForbidden();
    }

    public function test_a_temporary_password_must_be_changed_before_the_profile(): void
    {
        $user = User::factory()->for($this->schoolAdmin()->school)->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('profile'))->assertRedirect(route('password.edit'));
    }
}
