<?php

namespace Tests\Feature;

use App\Enums\ClassLevel;
use App\Enums\Cycle;
use App\Enums\Role;
use App\Enums\Section;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SectionSeparationTest extends TestCase
{
    use RefreshDatabase;

    private User $principal;

    private User $primaryDirector;

    private SchoolClass $primaryClass;

    private SchoolClass $secondaryClass;

    private Student $primaryPupil;

    private Student $secondaryPupil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->principal = $this->schoolAdmin();
        $school = $this->principal->school;
        $this->primaryDirector = User::factory()->admin()->for($school)->inSection(Section::Basic)->create();

        $year = $school->currentAcademicYear;
        $this->primaryClass = SchoolClass::factory()->for($school)->for($year)->create(['name' => 'CM1 A', 'level' => ClassLevel::Cm1]);
        $this->secondaryClass = SchoolClass::factory()->for($school)->for($year)->create(['name' => '6e A', 'level' => ClassLevel::Sixth]);

        $this->primaryPupil = Enrollment::factory()->create(['school_class_id' => $this->primaryClass->id])->student;
        $this->secondaryPupil = Enrollment::factory()->create(['school_class_id' => $this->secondaryClass->id])->student;
    }

    public function test_a_section_director_only_sees_their_section(): void
    {
        $this->actingAs($this->primaryDirector);

        $this->get(route('students.index'))
            ->assertOk()
            ->assertSee($this->primaryPupil->registration_number)
            ->assertDontSee($this->secondaryPupil->registration_number);

        $this->get(route('classes.index'))->assertSee('CM1 A')->assertDontSee('6e A');

        $this->get(route('classes.show', $this->secondaryClass))->assertNotFound();
        $this->get(route('students.show', $this->secondaryPupil))->assertNotFound();
        $this->get(route('students.edit', $this->secondaryPupil))->assertNotFound();
    }

    public function test_the_principal_sees_the_whole_school_and_can_focus_on_one_section(): void
    {
        $this->actingAs($this->principal);

        $this->get(route('classes.index'))->assertSee('CM1 A')->assertSee('6e A');

        $this->post(route('section.switch'), ['section' => 'secondary'])->assertRedirect(route('dashboard'));
        $this->get(route('classes.index'))->assertDontSee('CM1 A')->assertSee('6e A');

        $this->post(route('section.switch'), ['section' => ''])->assertRedirect(route('dashboard'));
        $this->get(route('classes.index'))->assertSee('CM1 A')->assertSee('6e A');
    }

    public function test_a_section_director_cannot_switch_to_the_other_section(): void
    {
        $this->actingAs($this->primaryDirector)
            ->post(route('section.switch'), ['section' => 'secondary'])
            ->assertForbidden();
    }

    public function test_a_school_cannot_be_focused_on_a_section_it_does_not_teach(): void
    {
        $this->principal->school->update(['cycles' => [Cycle::Primary]]);

        $this->actingAs($this->principal)
            ->post(route('section.switch'), ['section' => 'secondary'])
            ->assertSessionHasErrors('section');
    }

    public function test_a_section_director_cannot_enroll_a_pupil_in_the_other_section(): void
    {
        Livewire::actingAs($this->primaryDirector)
            ->test('pages::students.form')
            ->set([
                'last_name' => 'Dossou', 'first_name' => 'Aïcha', 'gender' => 'F',
                'class_id' => (string) $this->secondaryClass->id,
                'guardian_last_name' => 'Dossou', 'guardian_first_name' => 'Paul', 'guardian_phone' => '+229 01 97 00 00 01',
            ])
            ->call('save')
            ->assertHasErrors(['class_id' => 'in']);
    }

    public function test_a_section_director_only_creates_classes_of_their_levels(): void
    {
        $component = Livewire::actingAs($this->primaryDirector)->test('pages::classes.index');

        $this->assertNotContains(ClassLevel::Sixth, $component->instance()->levels);

        $component->set('level', '6e')->set('name', '6e B')->call('create')->assertHasErrors(['level']);
        $component->set('level', 'CE1')->set('name', 'CE1 A')->call('create')->assertHasNoErrors();
    }

    public function test_only_teachers_of_the_class_section_can_be_assigned_to_it(): void
    {
        $school = $this->principal->school;
        $secondaryTeacher = User::factory()->for($school)->inSection(Section::Secondary)->create(['role' => Role::Teacher]);
        $sharedTeacher = User::factory()->for($school)->create(['role' => Role::Teacher]);
        $subject = Subject::factory()->for($school)->create(['cycle' => Cycle::Primary]);

        $component = Livewire::actingAs($this->principal)->test('pages::classes.show', ['schoolClass' => $this->primaryClass]);
        $this->assertSame([$sharedTeacher->id], $component->instance()->teachers->modelKeys());

        $component->set('subject_id', (string) $subject->id)
            ->set('teacher_id', (string) $secondaryTeacher->id)
            ->call('addSubject')
            ->assertHasErrors(['teacher_id']);
    }

    public function test_a_class_only_offers_the_subjects_of_its_cycle(): void
    {
        $school = $this->principal->school;
        $primary = Subject::factory()->for($school)->create(['cycle' => Cycle::Primary]);
        $secondary = Subject::factory()->for($school)->create(['cycle' => Cycle::Secondary]);
        $shared = Subject::factory()->for($school)->create(['cycle' => null]);

        $component = Livewire::actingAs($this->principal)->test('pages::classes.show', ['schoolClass' => $this->primaryClass]);

        $this->assertEqualsCanonicalizing([$primary->id, $shared->id], $component->instance()->availableSubjects->modelKeys());

        $component->set('subject_id', (string) $secondary->id)->call('addSubject')->assertHasErrors(['subject_id']);
    }

    public function test_a_section_director_creates_staff_for_their_section_only(): void
    {
        $component = Livewire::actingAs($this->primaryDirector)->test('pages::staff.index');

        $component->set(['name' => 'Censeur', 'email' => 'censeur@ecole.bj', 'role' => 'admin', 'section' => 'secondary'])
            ->call('create')
            ->assertHasErrors(['section']);

        $component->set(['name' => 'Directeur bis', 'email' => 'dg@ecole.bj', 'role' => 'admin', 'section' => ''])
            ->call('create')
            ->assertHasErrors(['section' => 'required']);

        $component->set(['name' => 'Mme Titulaire', 'email' => 'titulaire@ecole.bj', 'role' => 'teacher', 'section' => 'basic'])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(Section::Basic, User::where('email', 'titulaire@ecole.bj')->sole()->section);
    }

    public function test_the_accountant_always_covers_the_whole_school(): void
    {
        Livewire::actingAs($this->principal)
            ->test('pages::staff.index')
            ->set(['name' => 'M. Caisse', 'email' => 'caisse@ecole.bj', 'role' => 'accountant', 'section' => 'basic'])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertNull(User::where('email', 'caisse@ecole.bj')->sole()->section);
    }

    public function test_school_settings_are_reserved_to_the_principal(): void
    {
        $this->actingAs($this->primaryDirector)->get(route('settings'))->assertForbidden();
        $this->actingAs($this->principal)->get(route('settings'))->assertOk();
    }

    public function test_a_section_director_only_changes_the_subjects_of_their_cycles(): void
    {
        $school = $this->principal->school;
        $shared = Subject::factory()->for($school)->create(['cycle' => null]);
        $secondary = Subject::factory()->for($school)->create(['cycle' => Cycle::Secondary]);

        Livewire::actingAs($this->primaryDirector)->test('pages::subjects.index')->call('edit', $shared->id)->assertForbidden();
        Livewire::actingAs($this->primaryDirector)->test('pages::subjects.index')->call('delete', $secondary->id)->assertNotFound();

        $this->assertModelExists($secondary);

        Livewire::actingAs($this->primaryDirector)
            ->test('pages::subjects.index')
            ->set(['name' => 'Lecture', 'code' => 'LECT', 'cycle' => 'secondary'])
            ->call('save')
            ->assertHasErrors(['cycle']);

        Livewire::actingAs($this->primaryDirector)
            ->test('pages::subjects.index')
            ->set(['name' => 'Lecture', 'code' => 'LECT', 'cycle' => 'primary'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Cycle::Primary, Subject::withoutGlobalScopes()->where('code', 'LECT')->sole()->cycle);
    }
}
