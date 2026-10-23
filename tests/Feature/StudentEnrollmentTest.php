<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class StudentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->schoolAdmin();
        $this->class = SchoolClass::factory()->create([
            'academic_year_id' => $this->admin->school->currentAcademicYear->id,
            'name' => '6e A',
        ]);
        $this->actingAs($this->admin);
    }

    private function fillForm(array $overrides = []): Testable
    {
        return Livewire::test('pages::students.form')->set(array_merge([
            'last_name' => 'Dossou',
            'first_name' => 'Aïcha',
            'gender' => 'F',
            'birth_date' => '2014-03-12',
            'class_id' => (string) $this->class->id,
            'guardian_last_name' => 'Dossou',
            'guardian_first_name' => 'Marcel',
            'guardian_phone' => '+229 01 97 12 34 56',
            'guardian_relationship' => 'father',
        ], $overrides));
    }

    public function test_an_admin_enrolls_a_student_with_a_generated_matricule(): void
    {
        $this->fillForm()->call('save')->assertHasNoErrors();

        $student = Student::sole();
        $this->assertSame('2026-0001', $student->registration_number);
        $this->assertSame($this->class->id, $student->currentEnrollment->school_class_id);
        $this->assertSame($this->admin->school_id, $student->school_id);

        $guardian = $student->guardians->sole();
        $this->assertSame('+2290197123456', $guardian->phone);
        $this->assertTrue((bool) $guardian->pivot->is_primary_contact);
    }

    public function test_matricules_follow_the_last_one_of_the_year(): void
    {
        Student::factory()->create(['school_id' => $this->admin->school_id, 'registration_number' => '2026-0041']);

        $this->fillForm()->call('save');

        $this->assertDatabaseHas('students', ['first_name' => 'Aïcha', 'registration_number' => '2026-0042']);
    }

    public function test_a_guardian_with_the_same_phone_is_reused_for_siblings(): void
    {
        $this->fillForm()->call('save');
        $this->fillForm(['first_name' => 'Koffi', 'gender' => 'M', 'guardian_phone' => '+229 0197123456'])->call('save');

        $this->assertSame(1, Guardian::count());
        $this->assertSame(2, Guardian::sole()->students()->count());
    }

    public function test_required_fields_are_validated(): void
    {
        Livewire::test('pages::students.form')
            ->call('save')
            ->assertHasErrors(['last_name', 'first_name', 'gender', 'class_id', 'guardian_last_name', 'guardian_first_name', 'guardian_phone']);

        $this->assertSame(0, Student::count());
    }

    public function test_a_class_from_another_school_is_rejected(): void
    {
        $otherClass = SchoolClass::factory()->create();

        $this->fillForm(['class_id' => (string) $otherClass->id])->call('save')->assertHasErrors('class_id');
    }

    public function test_a_matricule_already_used_in_the_school_is_rejected(): void
    {
        Student::factory()->create(['school_id' => $this->admin->school_id, 'registration_number' => '2026-0007']);

        $this->fillForm(['registration_number' => '2026-0007'])->call('save')->assertHasErrors('registration_number');
    }

    public function test_the_same_matricule_is_allowed_in_another_school(): void
    {
        Student::factory()->create(['registration_number' => '2026-0007']);

        $this->fillForm(['registration_number' => '2026-0007'])->call('save')->assertHasNoErrors();
    }

    public function test_an_admin_updates_a_student_and_moves_them_to_another_class(): void
    {
        $this->fillForm()->call('save');
        $student = Student::sole();
        $newClass = SchoolClass::factory()->create(['academic_year_id' => $this->class->academic_year_id, 'name' => '6e B']);

        Livewire::test('pages::students.form', ['student' => $student])
            ->assertSet('guardian_first_name', 'Marcel')
            ->set('first_name', 'Aïcha Grâce')
            ->set('class_id', (string) $newClass->id)
            ->call('save')
            ->assertHasNoErrors();

        $student->refresh();
        $this->assertSame('Aïcha Grâce', $student->first_name);
        $this->assertSame(1, $student->enrollments()->count());
        $this->assertSame($newClass->id, $student->currentEnrollment->school_class_id);
    }

    public function test_student_names_are_escaped_on_the_profile_page(): void
    {
        $student = Student::factory()->create([
            'school_id' => $this->admin->school_id,
            'first_name' => '<script>alert("x")</script>',
        ]);

        $this->get(route('students.show', $student))
            ->assertOk()
            ->assertDontSee('<script>alert("x")</script>', escape: false)
            ->assertSee('&lt;script&gt;', escape: false);
    }
}
